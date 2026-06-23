<?php

declare(strict_types=1);

/**
 * يُرفع عند إضافة أو تعديل خطوات ترحيل PHP داخل orange_catalog_ensure_schema() حتى تُعاد مزامنة القواعد القائمة.
 * لإجبار تشغيل الجسم الكامل يدوياً: احذف الصف من orange_catalog_schema_checkpoint أو ارفع هذا الرقم.
 *
 * @see IBRAHIM_ORANGE_MASTER.txt §2
 */
if (! defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
    define('ORANGE_CATALOG_SCHEMA_PHP_REVISION', 100);
}

/** يطابق دائماً ORANGE_CATALOG_SCHEMA_PHP_REVISION — اسم موازٍ لخطط «Schema Gate» (مرجع واحد للرقم). */
if (! defined('ORANGE_SCHEMA_CODE_VERSION')) {
    define('ORANGE_SCHEMA_CODE_VERSION', ORANGE_CATALOG_SCHEMA_PHP_REVISION);
}

/**
 * Ensures catalog tables and columns for colors, patterns, size families, colorways, and variant FKs exist.
 * Safe to call multiple times per request (uses static guard).
 */
function orange_table_exists(PDO $pdo, string $table): bool
{
    if (! isset($GLOBALS['orange_schema_table_cache']) || ! is_array($GLOBALS['orange_schema_table_cache'])) {
        $GLOBALS['orange_schema_table_cache'] = [];
    }
    $cache = &$GLOBALS['orange_schema_table_cache'];
    $cacheKey = strtolower($table);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?)'
    );
    $stmt->execute([$table]);
    $exists = (int) $stmt->fetchColumn() > 0;
    $cache[$cacheKey] = $exists;

    return $exists;
}

/**
 * بعد CREATE TABLE في نفس الطلب: orange_table_exists() تخزّن false قبل الإنشاء فيجب إبطال الكاش.
 */
function orange_schema_invalidate_table_exists(string $table): void
{
    if (! isset($GLOBALS['orange_schema_table_cache']) || ! is_array($GLOBALS['orange_schema_table_cache'])) {
        return;
    }
    unset($GLOBALS['orange_schema_table_cache'][strtolower($table)]);
}

/**
 * إبطال كاش وجود عمود (بعد ALTER DROP/ADD في نفس الطلب أو عند الاشتباه بخلل INFORMATION_SCHEMA).
 */
function orange_schema_invalidate_column_check(string $table, string $column): void
{
    if (! isset($GLOBALS['orange_schema_column_cache']) || ! is_array($GLOBALS['orange_schema_column_cache'])) {
        return;
    }
    unset($GLOBALS['orange_schema_column_cache'][$table . '.' . $column]);
}

function orange_table_has_column(PDO $pdo, string $table, string $column): bool
{
    if (! isset($GLOBALS['orange_schema_column_cache']) || ! is_array($GLOBALS['orange_schema_column_cache'])) {
        $GLOBALS['orange_schema_column_cache'] = [];
    }
    $cache = &$GLOBALS['orange_schema_column_cache'];
    $k = $table . '.' . $column;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    $exists = (int) $stmt->fetchColumn() > 0;
    $cache[$k] = $exists;

    return $exists;
}

function orange_catalog_safe_exec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] catalog_schema: ' . $e->getMessage());
        }
    }
}

/**
 * عند تفعيل المتجر الموحّد: إن امتلأ عمود product_type_id لكل المنتجات بقيمة صالحة تُطبَّق مطابقة سياسة ERD بعد الترحيل (NOT NULL).
 * لا تُنفَّذ أي تعديل ما دام يوجد صف بلا نوع منتج أو بمرجعيته غير موجودة؛ يُسجَّل الخطأ فقط في الـ log إن تعذّر الـ MODIFY.
 */
function orange_catalog_ensure_products_product_type_id_not_null(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'products') || !orange_table_has_column($pdo, 'products', 'product_type_id')) {
        return;
    }
    require_once __DIR__ . '/catalog_taxonomy_migrate.php';
    if (!function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)) {
        return;
    }
    if (!orange_table_exists($pdo, 'product_types')) {
        return;
    }
    try {
        orange_catalog_try_modify_products_product_type_id_not_null($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_ensure_products_product_type_id_not_null: ' . $e->getMessage());
        }
    }
}

/**
 * المرحلة النهائية (متجر موحّد فقط): إسقاط أعمدة الفئة القديمة على المنتج بعد تعبئة/تحقّق product_type_id و NOT NULL.
 * لا يُنفَّذ أي إسقاط ما دام هناك صف بلا نوع منتج صالح.
 */
function orange_catalog_ensure_products_drop_legacy_classification_columns(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'products')) {
        return;
    }
    require_once __DIR__ . '/catalog_taxonomy_migrate.php';
    if (!function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)) {
        return;
    }
    $hasCatCol = orange_table_has_column($pdo, 'products', 'category_id');
    $hasSubCol = orange_table_has_column($pdo, 'products', 'subcategory_id');
    if (! $hasCatCol && ! $hasSubCol) {
        return;
    }
    if (!orange_table_has_column($pdo, 'products', 'product_type_id')) {
        return;
    }

    try {
        $bad = (int) $pdo->query(
            'SELECT COUNT(*) FROM products p WHERE p.product_type_id IS NULL OR p.product_type_id <= 0 OR NOT EXISTS (
                SELECT 1 FROM product_types pt WHERE pt.id = p.product_type_id
            )'
        )->fetchColumn();
        if ($bad > 0) {
            if (function_exists('error_log')) {
                error_log('[orange] legacy product classification drop skipped: products missing valid product_type_id (' . $bad . ')');
            }

            return;
        }

        orange_catalog_fill_legacy_product_row_cache($pdo);

        $fkSt = $pdo->prepare(
            'SELECT DISTINCT k.CONSTRAINT_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
             INNER JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS t
               ON k.CONSTRAINT_NAME = t.CONSTRAINT_NAME
              AND k.TABLE_SCHEMA = t.TABLE_SCHEMA
             WHERE k.TABLE_SCHEMA = DATABASE()
               AND k.TABLE_NAME = \'products\'
               AND k.COLUMN_NAME IN (\'category_id\', \'subcategory_id\')
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
               AND t.CONSTRAINT_TYPE = \'FOREIGN KEY\''
        );
        $fkSt->execute();
        foreach ($fkSt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [] as $fkName) {
            $fkName = trim((string) $fkName);
            if ($fkName === '') {
                continue;
            }
            orange_catalog_safe_exec($pdo, 'ALTER TABLE products DROP FOREIGN KEY `' . str_replace('`', '``', $fkName) . '`');
        }

        $ixSt = $pdo->prepare(
            'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = \'products\'
               AND COLUMN_NAME IN (\'category_id\', \'subcategory_id\')
               AND INDEX_NAME <> \'PRIMARY\''
        );
        $ixSt->execute();
        $indexes = array_values(array_unique(array_filter(array_map('trim', $ixSt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []))));
        foreach ($indexes as $ixName) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE products DROP INDEX `' . str_replace('`', '``', $ixName) . '`');
        }

        $drops = [];
        if ($hasCatCol) {
            $drops[] = 'DROP COLUMN `category_id`';
        }
        if ($hasSubCol) {
            $drops[] = 'DROP COLUMN `subcategory_id`';
        }
        if ($drops !== []) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE products ' . implode(', ', $drops));
            orange_schema_invalidate_column_check('products', 'category_id');
            orange_schema_invalidate_column_check('products', 'subcategory_id');
        }

        orange_catalog_try_modify_products_product_type_id_not_null($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_ensure_products_drop_legacy_classification_columns: ' . $e->getMessage());
        }
    }
}

/**
 * @internal
 */
function orange_catalog_try_modify_products_product_type_id_not_null(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'products') || !orange_table_has_column($pdo, 'products', 'product_type_id')) {
        return;
    }
    try {
        $ncol = $pdo->prepare(
            'SELECT IS_NULLABLE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $ncol->execute(['products', 'product_type_id']);
        $crow = $ncol->fetch(PDO::FETCH_ASSOC);
        if (! is_array($crow)) {
            return;
        }
        if (strtoupper((string) ($crow['IS_NULLABLE'] ?? '')) !== 'YES') {
            return;
        }
        $bad = (int) $pdo->query(
            'SELECT COUNT(*) FROM products p WHERE p.product_type_id IS NULL OR p.product_type_id <= 0 OR NOT EXISTS (
                SELECT 1 FROM product_types pt WHERE pt.id = p.product_type_id
            )'
        )->fetchColumn();
        if ($bad > 0) {
            return;
        }

        orange_catalog_safe_exec($pdo, 'ALTER TABLE products MODIFY COLUMN product_type_id INT UNSIGNED NOT NULL');
        orange_schema_invalidate_column_check('products', 'product_type_id');
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_try_modify_products_product_type_id_not_null: ' . $e->getMessage());
        }
    }
}

function orange_catalog_schema_checkpoint_ensure_table(PDO $pdo): void
{
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS orange_catalog_schema_checkpoint (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            php_revision INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function orange_catalog_schema_checkpoint_matches(PDO $pdo, int $expectedRevision): bool
{
    try {
        orange_catalog_schema_checkpoint_ensure_table($pdo);
        $st = $pdo->query('SELECT php_revision FROM orange_catalog_schema_checkpoint WHERE id = 1 LIMIT 1');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($row === false || ! is_array($row)) {
            return false;
        }

        return (int) ($row['php_revision'] ?? 0) === $expectedRevision;
    } catch (Throwable $e) {
        return false;
    }
}

function orange_catalog_schema_checkpoint_save(PDO $pdo, int $revision): void
{
    try {
        orange_catalog_schema_checkpoint_ensure_table($pdo);
        $ins = $pdo->prepare(
            'INSERT INTO orange_catalog_schema_checkpoint (id, php_revision) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE php_revision = VALUES(php_revision)'
        );
        $ins->execute([$revision]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] catalog_schema checkpoint: ' . $e->getMessage());
        }
    }
}

/**
 * بوابة إصدار المخطط (مرادف لـ orange_catalog_schema_checkpoint): صف واحد id=1.
 * يُزامَن مع ORANGE_SCHEMA_CODE_VERSION عند اكتمال ترحيل PHP الكامل أو عند المسار السريع.
 */
function orange_schema_meta_ensure_table(PDO $pdo): void
{
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS orange_schema_meta (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            version INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function orange_schema_meta_matches(PDO $pdo, int $expectedRevision): bool
{
    try {
        orange_schema_meta_ensure_table($pdo);
        $st = $pdo->query('SELECT version FROM orange_schema_meta WHERE id = 1 LIMIT 1');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($row === false || ! is_array($row)) {
            return false;
        }

        return (int) ($row['version'] ?? 0) === $expectedRevision;
    } catch (Throwable $e) {
        return false;
    }
}

function orange_schema_meta_save(PDO $pdo, int $version): void
{
    try {
        orange_schema_meta_ensure_table($pdo);
        $ins = $pdo->prepare(
            'INSERT INTO orange_schema_meta (id, version) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE version = VALUES(version), updated_at = CURRENT_TIMESTAMP'
        );
        $ins->execute([$version]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_schema_meta: ' . $e->getMessage());
        }
    }
}

/**
 * توحيد slug مع path_segment (نفس مبدأ حفظ القناة الجديدة) — لمرة واحدة لكل قاعدة.
 */
function orange_catalog_allocate_unique_slug_from_base(string $base, array &$usedSlugs): string
{
    $b = strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', $base));
    if ($b === '') {
        $b = 'channel';
    }
    for ($i = 0; $i < 500; $i++) {
        $try = $i === 0 ? $b : $b . '-' . $i;
        if (!isset($usedSlugs[$try])) {
            $usedSlugs[$try] = true;

            return $try;
        }
    }

    return $b . '-' . bin2hex(random_bytes(3));
}

function orange_catalog_migrate_storefront_accounts_slug(PDO $pdo, string $oldSlug, string $newSlug): void
{
    if ($oldSlug === $newSlug || $oldSlug === '' || $newSlug === '') {
        return;
    }
    if (!function_exists('orange_table_exists') || !orange_table_exists($pdo, 'storefront_accounts')) {
        return;
    }
    if (!orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
        return;
    }
    try {
        $st = $pdo->prepare(
            'UPDATE storefront_accounts SET registered_channel_slug = ? WHERE registered_channel_slug = ?'
        );
        $st->execute([$newSlug, $oldSlug]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] migrate accounts slug: ' . $e->getMessage());
        }
    }
}

function orange_catalog_migrate_channel_slugs_align_path_segment_v1(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'channels')) {
        return;
    }
    require_once __DIR__ . '/schema_migrations.php';
    orange_schema_migrations_ensure_table($pdo);
    $marker = 'php_channel_slugs_align_path_segment_v1';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    try {
        $rows = $pdo->query('SELECT id, slug, path_segment FROM channels ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
            $ins->execute([$marker]);

            return;
        }
        $toChange = [];
        foreach ($rows as $r) {
            $ps = strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', (string) ($r['path_segment'] ?? '')));
            if ($ps === '') {
                continue;
            }
            $old = strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', (string) ($r['slug'] ?? '')));
            if ($old === $ps) {
                continue;
            }
            $toChange[] = [
                'id' => (int) $r['id'],
                'old' => (string) ($r['slug'] ?? ''),
                'base' => $ps,
            ];
        }
        if ($toChange === []) {
            $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
            $ins->execute([$marker]);

            return;
        }

        $pdo->beginTransaction();
        foreach ($toChange as $c) {
            $tmp = '__mig_' . $c['id'] . '_' . bin2hex(random_bytes(4));
            $st = $pdo->prepare('UPDATE channels SET slug = ? WHERE id = ?');
            $st->execute([$tmp, $c['id']]);
        }
        $used = [];
        $idToNew = [];
        foreach ($toChange as $c) {
            $newSlug = orange_catalog_allocate_unique_slug_from_base($c['base'], $used);
            $idToNew[$c['id']] = $newSlug;
            $st = $pdo->prepare('UPDATE channels SET slug = ? WHERE id = ?');
            $st->execute([$newSlug, $c['id']]);
        }
        foreach ($toChange as $c) {
            $oldS = $c['old'];
            $newS = $idToNew[$c['id']] ?? '';
            if ($oldS !== '' && $newS !== '' && $oldS !== $newS) {
                orange_catalog_migrate_storefront_accounts_slug($pdo, $oldS, $newS);
            }
        }
        $pdo->commit();
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('error_log')) {
            error_log('[orange] channel slug align migration: ' . $e->getMessage());
        }
    }
}

/**
 * طول CHARACTER_MAXIMUM_LENGTH لعمود varchar/char، أو 0 إن لم يوجد / غير قابل للتطبيق.
 */
function orange_schema_varchar_max_length(PDO $pdo, string $table, string $column): int
{
    try {
        $stmt = $pdo->prepare(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null) {
            return 0;
        }

        return (int) $raw;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * عند نشر قاعدة جديدة أو جدول accounts فارغ: إدراج جذور دليل افتراضية (1–7) بترميز utf8mb4.
 * الجذر 7: حسابات نظامية (خارج الميزانية) — off-balance / memorandum.
 * لا يعمل إن وُجدت أي صفوف — لن يستبدل دليلاً قائماً.
 */
function orange_catalog_seed_default_accounts_if_empty(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'accounts')) {
        return;
    }
    require_once __DIR__ . '/countries.php';
    $hasCountry = orange_table_has_column($pdo, 'accounts', 'country_id');
    $seedCountryId = $hasCountry ? orange_countries_default_id($pdo) : 0;
    try {
        if ($hasCountry && $seedCountryId > 0) {
            $stCnt = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE country_id = ?');
            $stCnt->execute([$seedCountryId]);
            $cnt = (int) $stCnt->fetchColumn();
        } else {
            $cnt = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        }
        if ($cnt > 0) {
            return;
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] accounts seed count: ' . $e->getMessage());
        }

        return;
    }

    $hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
    $hasNameEn = orange_table_has_column($pdo, 'accounts', 'name_en');
    $hasSuspended = orange_table_has_column($pdo, 'accounts', 'is_suspended');
    $hasNb = orange_table_has_column($pdo, 'accounts', 'normal_balance');
    $hasPar = orange_table_has_column($pdo, 'accounts', 'parent_id');
    $hasCode = orange_table_has_column($pdo, 'accounts', 'code');
    if (!$hasCode) {
        return;
    }

    $roots = [
        ['code' => '1', 'name' => 'الأصول', 'name_en' => 'Assets'],
        ['code' => '2', 'name' => 'الخصوم', 'name_en' => 'Liabilities'],
        ['code' => '3', 'name' => 'حقوق الملكية', 'name_en' => 'Equity'],
        ['code' => '4', 'name' => 'الإيرادات', 'name_en' => 'Revenue'],
        ['code' => '5', 'name' => 'تكلفة المبيعات', 'name_en' => 'Cost of sales'],
        ['code' => '6', 'name' => 'المصروفات', 'name_en' => 'Expenses'],
        ['code' => '7', 'name' => 'حسابات نظامية (خارج الميزانية)', 'name_en' => 'Off-balance sheet accounts'],
    ];

    $lock = 'orange_seed_coa';
    $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 10)')->fetchColumn();
    if ((int) $lk !== 1) {
        return;
    }
    try {
        if ($hasCountry && $seedCountryId > 0) {
            $stCnt2 = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE country_id = ?');
            $stCnt2->execute([$seedCountryId]);
            $cnt2 = (int) $stCnt2->fetchColumn();
        } else {
            $cnt2 = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        }
        if ($cnt2 > 0) {
            return;
        }
        foreach ($roots as $r) {
            $cols = ['name'];
            $vals = [$r['name']];
            $cols[] = 'code';
            $vals[] = $r['code'];
            if ($hasCountry && $seedCountryId > 0) {
                $cols[] = 'country_id';
                $vals[] = $seedCountryId;
            }
            if ($hasPar) {
                $cols[] = 'parent_id';
                $vals[] = null;
            }
            if ($hasGrp) {
                $cols[] = 'is_group';
                $vals[] = 1;
            }
            if ($hasNameEn) {
                $cols[] = 'name_en';
                $vals[] = $r['name_en'];
            }
            if ($hasSuspended) {
                $cols[] = 'is_suspended';
                $vals[] = 0;
            }
            if ($hasNb) {
                $cols[] = 'normal_balance';
                $vals[] = null;
            }
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare('INSERT INTO accounts (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] accounts seed: ' . $e->getMessage());
        }
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')');
    }
}

/**
 * للمسارات القراءة فقط التي تحتاج اتصالاً آمناً وجداول المتجر الأساسية (مثل manifest.json)
 * دون تشغيل كامل orange_catalog_ensure_schema() (الترحيلات الثقيلة).
 * آمن لاستدعائه عدة مرات لكل طلب (حارس static).
 */
function orange_catalog_ensure_storefront_read_bootstrap(PDO $pdo): void
{
    static $charsetApplied = false;
    if (!$charsetApplied) {
        orange_catalog_safe_exec($pdo, 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $charsetApplied = true;
    }

    static $bootDone = false;
    if ($bootDone) {
        return;
    }

    require_once __DIR__ . '/catalog_bootstrap_store.php';
    orange_catalog_bootstrap_store_tables($pdo);
    $bootDone = true;
}

/** طلب HTTP لمسار الأدمن (لتفريق مهام الترحيل الثقيلة عن الواجهة العامة). */
function orange_catalog_is_admin_http_request(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

    return str_contains($uri, '/admin/') || str_contains($script, '/admin/');
}

/**
 * صفحات المتجر والقنوات (pages/* عبر storefront-dispatch): bootstrap خفيف + بوابة المخطط فقط.
 * لا runtime_light_hooks ولا إعادة ترقيم id — تلك على CLI أو الأدمن.
 */
function orange_catalog_ensure_storefront_page(PDO $pdo): void
{
    orange_catalog_ensure_storefront_read_bootstrap($pdo);
    try {
        orange_schema_check_and_bootstrap($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] storefront_page schema bootstrap failed (non-fatal): ' . $e->getMessage());
        }
    }
}

/**
 * جداول ربط حسابات القيود التلقائية + نسب (مثل الاحتياطي القانوني).
 * المسار السريع عند تطابق checkpoint كان يتخطى النواة الكاملة فلا يُنشَأ orange_gl_setting_alloc
 * ولا تُقرأ/تُحفَظ النسبة — لذا يُستدعى هذا من المسار السريع ومن شاشة الإعدادات.
 */
function orange_catalog_ensure_gl_account_settings_alloc_tables(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'accounts')) {
        return;
    }

    if (!orange_table_exists($pdo, 'orange_gl_account_settings')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_gl_account_settings (
                setting_key VARCHAR(64) NOT NULL,
                account_id INT NOT NULL,
                journal_type_id INT NULL,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE current_timestamp(),
                PRIMARY KEY (setting_key),
                KEY idx_gl_set_account (account_id),
                KEY idx_gl_set_jt (journal_type_id),
                CONSTRAINT orange_fk_gl_setting_account FOREIGN KEY (account_id) REFERENCES accounts (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
        orange_schema_invalidate_table_exists('orange_gl_account_settings');
    }
    if (orange_table_exists($pdo, 'orange_gl_account_settings')
        && !orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orange_gl_account_settings ADD COLUMN journal_type_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_gl_set_jt ON orange_gl_account_settings (journal_type_id)');
    }

    if (!orange_table_exists($pdo, 'orange_gl_setting_alloc')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_gl_setting_alloc (
                setting_key VARCHAR(64) NOT NULL,
                percent_value DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('orange_gl_setting_alloc');
    }

    orange_catalog_ensure_orange_gl_journal_type_rules($pdo);
}

/**
 * جدول قواعد ربط نوع اليومية — يُستدعى من مسار الإعدادات السريع أيضاً حتى يُضاف عمود payment_terms إن وُجد جدول قديم.
 */
function orange_catalog_ensure_orange_gl_journal_type_rules(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'journal_types')) {
        return;
    }

    if (!orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_gl_journal_type_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                journal_type_id INT NOT NULL,
                payment_terms VARCHAR(8) NOT NULL DEFAULT \'\' COMMENT \'cash|credit for PIN/PDN; empty=standard\',
                debit_setting_key VARCHAR(64) NOT NULL,
                credit_setting_key VARCHAR(64) NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ojtr_jt_terms (journal_type_id, payment_terms),
                KEY idx_ojtr_debit (debit_setting_key),
                KEY idx_ojtr_credit (credit_setting_key),
                CONSTRAINT orange_fk_ojtr_jt FOREIGN KEY (journal_type_id) REFERENCES journal_types (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('orange_gl_journal_type_rules');
    }

    if (orange_table_exists($pdo, 'orange_gl_journal_type_rules')
        && !orange_table_has_column($pdo, 'orange_gl_journal_type_rules', 'payment_terms')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_journal_type_rules ADD COLUMN payment_terms VARCHAR(8) NOT NULL DEFAULT \'\' AFTER journal_type_id'
        );
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orange_gl_journal_type_rules DROP INDEX uq_ojtr_journal_type');
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_journal_type_rules ADD UNIQUE KEY uq_ojtr_jt_terms (journal_type_id, payment_terms)'
        );
        try {
            $pdo->exec(
                "UPDATE orange_gl_journal_type_rules r
                 INNER JOIN journal_types jt ON jt.id = r.journal_type_id AND jt.code IN ('PIN','PDN')
                 SET r.payment_terms = 'cash'
                 WHERE r.payment_terms = ''"
            );
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orange_gl_journal_type_rules payment_terms migrate: ' . $e->getMessage());
            }
        }
        orange_schema_invalidate_table_exists('orange_gl_journal_type_rules');
    }
}

/**
 * يضمن اكتمال أعمدة الموردين حتى في المسار السريع (meta/checkpoint match).
 * هذا يمنع بقاء شاشة الموردين على الحقول القديمة عندما تكون ترقية الأعمدة خارج سلسلة NNN.sql.
 */
function orange_catalog_ensure_suppliers_schema(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'suppliers')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE suppliers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NULL,
                name VARCHAR(160) NOT NULL DEFAULT \'\',
                status VARCHAR(16) NOT NULL DEFAULT \'active\',
                phone_country_dial VARCHAR(8) NULL,
                phone_national VARCHAR(32) NULL,
                phone VARCHAR(40) NULL,
                currency_code VARCHAR(8) NOT NULL DEFAULT \'KWD\',
                payment_mode VARCHAR(16) NOT NULL DEFAULT \'cash\',
                payment_terms_days INT NULL,
                tax_profile VARCHAR(16) NOT NULL DEFAULT \'exempt\',
                tax_number VARCHAR(64) NULL,
                contact_person VARCHAR(160) NULL,
                email VARCHAR(255) NULL,
                commercial_reg VARCHAR(64) NULL,
                address_line VARCHAR(255) NULL,
                city_area VARCHAR(160) NULL,
                opening_balance DECIMAL(18,4) NULL,
                credit_limit DECIMAL(18,4) NULL,
                bank_name VARCHAR(160) NULL,
                bank_iban VARCHAR(64) NULL,
                bank_account_holder VARCHAR(160) NULL,
                preferred_warehouse_id INT NULL,
                block_reason VARCHAR(255) NULL,
                attachments_json TEXT NULL,
                notes VARCHAR(255) NULL,
                payable_account_id INT NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_suppliers_status (status),
                UNIQUE KEY uq_suppliers_phone (phone),
                UNIQUE KEY uq_suppliers_code (code),
                UNIQUE KEY uq_suppliers_tax_number (tax_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('suppliers');
    }
    if (!orange_table_exists($pdo, 'suppliers')) {
        return;
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'name')) {
        if (orange_table_has_column($pdo, 'suppliers', 'name_ar')) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN name VARCHAR(160) NOT NULL DEFAULT \'\'');
            orange_catalog_safe_exec($pdo, 'UPDATE suppliers SET name = COALESCE(NULLIF(TRIM(name_ar), \'\'), name)');
        } else {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN name VARCHAR(160) NOT NULL DEFAULT \'\'');
        }
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'phone')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN phone VARCHAR(40) NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'notes')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN notes VARCHAR(255) NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'code')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN code VARCHAR(32) NULL');
        orange_catalog_safe_exec($pdo, 'CREATE UNIQUE INDEX uq_suppliers_code ON suppliers (code)');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'status')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT \'active\' AFTER name');
    }
    $hasLegacySupplierIsActive = orange_table_has_column($pdo, 'suppliers', 'is_active');
    $hasLegacySupplierIsBlocked = orange_table_has_column($pdo, 'suppliers', 'is_blocked');
    if (orange_table_has_column($pdo, 'suppliers', 'status')) {
        if ($hasLegacySupplierIsActive && $hasLegacySupplierIsBlocked) {
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE suppliers
                 SET status = CASE
                    WHEN COALESCE(is_blocked, 0) = 1 THEN 'blocked'
                    WHEN COALESCE(is_active, 1) = 1 THEN 'active'
                    ELSE 'inactive'
                 END
                 WHERE status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ('active', 'inactive', 'blocked')"
            );
        } elseif ($hasLegacySupplierIsBlocked) {
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE suppliers
                 SET status = CASE
                    WHEN COALESCE(is_blocked, 0) = 1 THEN 'blocked'
                    ELSE 'active'
                 END
                 WHERE status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ('active', 'inactive', 'blocked')"
            );
        } elseif ($hasLegacySupplierIsActive) {
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE suppliers
                 SET status = CASE
                    WHEN COALESCE(is_active, 1) = 1 THEN 'active'
                    ELSE 'inactive'
                 END
                 WHERE status IS NULL OR TRIM(status) = '' OR LOWER(TRIM(status)) NOT IN ('active', 'inactive', 'blocked')"
            );
        }
        orange_catalog_safe_exec(
            $pdo,
            "UPDATE suppliers
             SET status = CASE
                WHEN LOWER(TRIM(status)) = 'active' THEN 'active'
                WHEN LOWER(TRIM(status)) = 'inactive' THEN 'inactive'
                WHEN LOWER(TRIM(status)) = 'blocked' THEN 'blocked'
                ELSE 'active'
             END"
        );
    }
    if (orange_table_has_column($pdo, 'suppliers', 'is_active')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers DROP COLUMN is_active');
    }
    if (orange_table_has_column($pdo, 'suppliers', 'is_blocked')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers DROP COLUMN is_blocked');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'phone_country_dial')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN phone_country_dial VARCHAR(8) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'phone_national')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN phone_national VARCHAR(32) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'currency_code')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN currency_code VARCHAR(8) NOT NULL DEFAULT \'KWD\'');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'payment_mode')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN payment_mode VARCHAR(16) NOT NULL DEFAULT \'cash\'');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'payment_terms_days')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN payment_terms_days INT NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'tax_profile')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN tax_profile VARCHAR(16) NOT NULL DEFAULT \'exempt\'');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'tax_number')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN tax_number VARCHAR(64) NULL DEFAULT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE UNIQUE INDEX uq_suppliers_tax_number ON suppliers (tax_number)');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'contact_person')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN contact_person VARCHAR(160) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'email')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'commercial_reg')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN commercial_reg VARCHAR(64) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'address_line')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN address_line VARCHAR(255) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'city_area')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN city_area VARCHAR(160) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'opening_balance')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN opening_balance DECIMAL(18,4) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'credit_limit')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN credit_limit DECIMAL(18,4) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'bank_name')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN bank_name VARCHAR(160) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'bank_iban')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN bank_iban VARCHAR(64) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'bank_account_holder')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN bank_account_holder VARCHAR(160) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'preferred_warehouse_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN preferred_warehouse_id INT NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'block_reason')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN block_reason VARCHAR(255) NULL DEFAULT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'attachments_json')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN attachments_json TEXT NULL');
    }
    if (!orange_table_has_column($pdo, 'suppliers', 'payable_account_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN payable_account_id INT NULL DEFAULT NULL');
    }

    foreach ([
        'name',
        'phone',
        'notes',
        'code',
        'status',
        'phone_country_dial',
        'phone_national',
        'currency_code',
        'payment_mode',
        'payment_terms_days',
        'tax_profile',
        'tax_number',
        'contact_person',
        'email',
        'commercial_reg',
        'address_line',
        'city_area',
        'opening_balance',
        'credit_limit',
        'bank_name',
        'bank_iban',
        'bank_account_holder',
        'preferred_warehouse_id',
        'block_reason',
        'attachments_json',
        'payable_account_id',
    ] as $supplierCol) {
        orange_schema_invalidate_column_check('suppliers', $supplierCol);
    }
}

function orange_catalog_ensure_schema_core(PDO $pdo): void
{
    // Per-connection charset (avoids editing config.php; some hosts break PDO::MYSQL_ATTR_INIT_COMMAND).
    static $charsetApplied = false;
    if (!$charsetApplied) {
        orange_catalog_safe_exec($pdo, 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $charsetApplied = true;
    }

    static $done = false;
    if ($done) {
        return;
    }

    $schemaRev = ORANGE_CATALOG_SCHEMA_PHP_REVISION;
    $metaOk = orange_schema_meta_matches($pdo, $schemaRev);
    $ckOk = orange_catalog_schema_checkpoint_matches($pdo, $schemaRev);
    if ($metaOk || $ckOk) {
        orange_catalog_ensure_schema_fast_path_slice($pdo);
        if (! $metaOk && $ckOk) {
            orange_schema_meta_save($pdo, $schemaRev);
        }
        if (! $ckOk && $metaOk) {
            orange_catalog_schema_checkpoint_save($pdo, $schemaRev);
        }
        $done = true;

        return;
    }

    require_once __DIR__ . '/catalog_bootstrap_store.php';
    orange_catalog_bootstrap_store_tables($pdo);

    if (orange_table_exists($pdo, 'channels')) {
        if (!orange_table_has_column($pdo, 'channels', 'path_segment')) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE channels ADD COLUMN path_segment VARCHAR(64) NULL DEFAULT NULL AFTER slug');
            orange_catalog_safe_exec(
                $pdo,
                'CREATE UNIQUE INDEX uq_channels_path_segment ON channels (path_segment)'
            );
        }
        orange_catalog_safe_exec(
            $pdo,
            "UPDATE channels SET path_segment = 'tiktok' WHERE slug = 'orange' AND (path_segment IS NULL OR path_segment = '')"
        );
        orange_catalog_safe_exec(
            $pdo,
            "UPDATE channels SET path_segment = 'online' WHERE slug = 'blue' AND (path_segment IS NULL OR path_segment = '')"
        );
        orange_catalog_safe_exec(
            $pdo,
            "UPDATE channels SET path_segment = 'web' WHERE slug = 'black' AND (path_segment IS NULL OR path_segment = '')"
        );
        orange_catalog_migrate_channel_slugs_align_path_segment_v1($pdo);
        if (orange_table_has_column($pdo, 'channels', 'primary_color')) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE channels DROP COLUMN primary_color');
        }
    }

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS color_dictionary (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            hex_code VARCHAR(16) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS pattern_dictionary (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    /* رابط مستند الفاتورة/المردود العام عبر QR (س27 — استثناء معتمد 2026-06-12). */
    orange_catalog_ensure_document_public_tokens_table($pdo);

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_families (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            size_scheme_key VARCHAR(64) NOT NULL DEFAULT \'\',
            commercial_kind_key VARCHAR(32) NOT NULL DEFAULT \'\',
            sizing_category_key VARCHAR(64) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_size_families_sizing_scope (commercial_kind_key, sizing_category_key, size_scheme_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_family_sizes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            size_family_id INT NOT NULL,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            label_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            label_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_size_family_sizes_family (size_family_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_scheme_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_size_scheme_templates_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (orange_table_exists($pdo, 'size_scheme_templates')) {
        if (!orange_table_has_column($pdo, 'size_scheme_templates', 'name_fil')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE size_scheme_templates ADD COLUMN name_fil VARCHAR(191) NOT NULL DEFAULT \'\' AFTER name_en'
            );
        }
        if (!orange_table_has_column($pdo, 'size_scheme_templates', 'name_hi')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE size_scheme_templates ADD COLUMN name_hi VARCHAR(191) NOT NULL DEFAULT \'\' AFTER name_fil'
            );
        }
    }

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_scheme_template_sizes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            label_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            label_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_size_scheme_template_sizes_tpl (template_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS advisory_sizing_guides (
            id INT AUTO_INCREMENT PRIMARY KEY,
            size_family_id INT NULL DEFAULT NULL,
            department_id INT NULL DEFAULT NULL,
            size_scheme_template_id INT NULL DEFAULT NULL,
            commercial_kind_key VARCHAR(32) NOT NULL DEFAULT \'\',
            scope_kind VARCHAR(16) NOT NULL,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_advisory_sizing_guides_family (size_family_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (orange_table_exists($pdo, 'advisory_sizing_guides')) {
        try {
            $ixChk = $pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advisory_sizing_guides'
                   AND INDEX_NAME = 'uq_advisory_sizing_family_scope'"
            );
            if ($ixChk && (int) $ixChk->fetchColumn() > 0) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE advisory_sizing_guides DROP INDEX uq_advisory_sizing_family_scope'
                );
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $nullChk = $pdo->query(
                "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advisory_sizing_guides' AND COLUMN_NAME = 'size_family_id'
                 LIMIT 1"
            );
            $nr = $nullChk ? $nullChk->fetch(PDO::FETCH_ASSOC) : null;
            if (is_array($nr) && strtoupper((string) ($nr['IS_NULLABLE'] ?? '')) === 'NO') {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE advisory_sizing_guides MODIFY COLUMN size_family_id INT NULL DEFAULT NULL'
                );
            }
        } catch (Throwable $e) {
            // ignore
        }
        if (!orange_table_has_column($pdo, 'advisory_sizing_guides', 'department_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guides ADD COLUMN department_id INT NULL DEFAULT NULL AFTER size_family_id'
            );
            orange_schema_invalidate_column_check('advisory_sizing_guides', 'department_id');
        }
        if (!orange_table_has_column($pdo, 'advisory_sizing_guides', 'size_scheme_template_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guides ADD COLUMN size_scheme_template_id INT NULL DEFAULT NULL AFTER department_id'
            );
            orange_schema_invalidate_column_check('advisory_sizing_guides', 'size_scheme_template_id');
        }
        if (!orange_table_has_column($pdo, 'advisory_sizing_guides', 'commercial_kind_key')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guides ADD COLUMN commercial_kind_key VARCHAR(32) NOT NULL DEFAULT \'\' AFTER size_scheme_template_id'
            );
            orange_schema_invalidate_column_check('advisory_sizing_guides', 'commercial_kind_key');
        }
        // قرار المالك 2026-06-22: شكل الدليل (single | dual) لدعم الأطقم بتابين علوي/سفلي داخل دليل واحد.
        if (!orange_table_has_column($pdo, 'advisory_sizing_guides', 'layout_kind')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guides ADD COLUMN layout_kind VARCHAR(16) NOT NULL DEFAULT \'single\' AFTER scope_kind'
            );
            orange_schema_invalidate_column_check('advisory_sizing_guides', 'layout_kind');
        }
        if (orange_table_exists($pdo, 'advisory_sizing_guide_columns') && !orange_table_has_column($pdo, 'advisory_sizing_guide_columns', 'panel_kind')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guide_columns ADD COLUMN panel_kind VARCHAR(16) NOT NULL DEFAULT \'upper\' AFTER guide_id'
            );
            orange_schema_invalidate_column_check('advisory_sizing_guide_columns', 'panel_kind');
        }
        if (orange_table_exists($pdo, 'advisory_sizing_guide_rows') && !orange_table_has_column($pdo, 'advisory_sizing_guide_rows', 'panel_kind')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guide_rows ADD COLUMN panel_kind VARCHAR(16) NOT NULL DEFAULT \'upper\' AFTER guide_id'
            );
            orange_schema_invalidate_column_check('advisory_sizing_guide_rows', 'panel_kind');
        }
        if (orange_table_has_column($pdo, 'advisory_sizing_guides', 'name_en')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guides DROP COLUMN name_en'
            );
            orange_schema_invalidate_column_check('advisory_sizing_guides', 'name_en');
        }
        if (orange_table_has_column($pdo, 'advisory_sizing_guides', 'name_fil')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guides DROP COLUMN name_fil'
            );
            orange_schema_invalidate_column_check('advisory_sizing_guides', 'name_fil');
        }
        if (orange_table_has_column($pdo, 'advisory_sizing_guides', 'name_hi')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guides DROP COLUMN name_hi'
            );
            orange_schema_invalidate_column_check('advisory_sizing_guides', 'name_hi');
        }
        if (orange_table_has_column($pdo, 'advisory_sizing_guides', 'size_scheme_template_id')
            && orange_table_has_column($pdo, 'advisory_sizing_guides', 'commercial_kind_key')) {
            try {
                orange_catalog_safe_exec(
                    $pdo,
                    'UPDATE advisory_sizing_guides g
                     INNER JOIN size_families sf ON sf.id = g.size_family_id
                     SET g.commercial_kind_key = sf.commercial_kind_key,
                         g.size_scheme_template_id = sf.size_scheme_template_id
                     WHERE g.size_family_id IS NOT NULL AND g.size_family_id > 0
                       AND (
                         g.commercial_kind_key = \'\'
                         OR g.size_scheme_template_id IS NULL
                         OR g.size_scheme_template_id = 0
                       )'
                );
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS advisory_sizing_guide_columns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            guide_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            label_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            label_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            value_kind VARCHAR(16) NOT NULL DEFAULT \'text\',
            unit_hint VARCHAR(64) NOT NULL DEFAULT \'\',
            storage_measure VARCHAR(16) NOT NULL DEFAULT \'\',
            display_system VARCHAR(32) NOT NULL DEFAULT \'\',
            KEY idx_asgc_guide (guide_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (orange_table_exists($pdo, 'advisory_sizing_guide_columns') && !orange_table_has_column($pdo, 'advisory_sizing_guide_columns', 'storage_measure')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE advisory_sizing_guide_columns ADD COLUMN storage_measure VARCHAR(16) NOT NULL DEFAULT \'\' AFTER unit_hint'
        );
        orange_schema_invalidate_column_check('advisory_sizing_guide_columns', 'storage_measure');
    }
    if (orange_table_exists($pdo, 'advisory_sizing_guide_columns') && !orange_table_has_column($pdo, 'advisory_sizing_guide_columns', 'display_system')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE advisory_sizing_guide_columns ADD COLUMN display_system VARCHAR(32) NOT NULL DEFAULT \'\' AFTER storage_measure'
        );
        orange_schema_invalidate_column_check('advisory_sizing_guide_columns', 'display_system');
    }
    if (orange_table_exists($pdo, 'advisory_sizing_guide_columns') && orange_table_has_column($pdo, 'advisory_sizing_guide_columns', 'display_system')) {
        $stDsysMl = $pdo->query(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advisory_sizing_guide_columns' AND COLUMN_NAME = 'display_system'"
        );
        $mlDsys = $stDsysMl ? (int) $stDsysMl->fetchColumn() : 0;
        if ($mlDsys > 0 && $mlDsys < 32) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE advisory_sizing_guide_columns MODIFY COLUMN display_system VARCHAR(32) NOT NULL DEFAULT \'\''
            );
        }
    }

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS advisory_sizing_guide_rows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            guide_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            row_kind VARCHAR(16) NOT NULL DEFAULT \'data\',
            size_family_size_id INT NULL DEFAULT NULL,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            label_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            label_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            KEY idx_asgr_guide (guide_id),
            KEY idx_asgr_sfs (size_family_size_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS advisory_sizing_guide_cells (
            id INT AUTO_INCREMENT PRIMARY KEY,
            row_id INT NOT NULL,
            column_id INT NOT NULL,
            cell_value TEXT NULL,
            KEY idx_asgcell_row (row_id),
            KEY idx_asgcell_col (column_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS advisory_sizing_library_bundles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            department_id INT NULL DEFAULT NULL,
            size_scheme_template_id INT NULL DEFAULT NULL,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            commercial_kind_key VARCHAR(32) NOT NULL DEFAULT \'\',
            source_size_family_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_aslb_department (department_id),
            KEY idx_aslb_tpl (size_scheme_template_id),
            KEY idx_aslb_source_family (source_size_family_id),
            KEY idx_aslb_commercial (commercial_kind_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (orange_table_exists($pdo, 'advisory_sizing_library_bundles') && !orange_table_has_column($pdo, 'advisory_sizing_library_bundles', 'department_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE advisory_sizing_library_bundles ADD COLUMN department_id INT NULL DEFAULT NULL AFTER id');
    }
    if (orange_table_exists($pdo, 'advisory_sizing_library_bundles') && !orange_table_has_column($pdo, 'advisory_sizing_library_bundles', 'size_scheme_template_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE advisory_sizing_library_bundles ADD COLUMN size_scheme_template_id INT NULL DEFAULT NULL AFTER department_id');
    }

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_family_advisory_library_map (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            consumer_size_family_id INT NOT NULL,
            library_bundle_id INT UNSIGNED NOT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sfalm_consumer (consumer_size_family_id),
            KEY idx_sfalm_bundle (library_bundle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS product_colorways (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            primary_color_id INT NULL,
            secondary_color_id INT NULL,
            primary_pattern_id INT NULL,
            secondary_pattern_id INT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product_colorways_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS product_colorway_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_colorway_id INT NOT NULL,
            image_path VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            KEY idx_pci_colorway (product_colorway_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (orange_table_exists($pdo, 'product_colorways') && !orange_table_has_column($pdo, 'product_colorways', 'primary_pattern_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_colorways ADD COLUMN primary_pattern_id INT NULL AFTER secondary_color_id');
    }
    if (orange_table_exists($pdo, 'product_colorways') && !orange_table_has_column($pdo, 'product_colorways', 'secondary_pattern_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_colorways ADD COLUMN secondary_pattern_id INT NULL AFTER primary_pattern_id');
    }

    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'size_family_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN size_family_id INT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'sizing_guide_scope')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE products ADD COLUMN sizing_guide_scope VARCHAR(16) NOT NULL DEFAULT 'none'"
        );
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'sizing_advisory_guide_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE products ADD COLUMN sizing_advisory_guide_id INT NULL DEFAULT NULL AFTER sizing_guide_scope'
        );
    }
    // معاينة المنتج قبل النشر (docs/archive/ORANGE_PRODUCT_PREPUBLISH_PREVIEW_ROLLOUT.txt):
    // صفّ ظِلّ/مسودّة مخفيّ عن العميل، يحمل المحفوظ + غير المحفوظ لجلسة معاينة الأدمن. idempotent.
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'is_preview_draft')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN is_preview_draft TINYINT(1) NOT NULL DEFAULT 0');
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD INDEX idx_products_is_preview_draft (is_preview_draft)');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'preview_admin_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN preview_admin_id INT NULL DEFAULT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'preview_token')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN preview_token VARCHAR(64) NULL DEFAULT NULL');
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD INDEX idx_products_preview_token (preview_token)');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'preview_source_product_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN preview_source_product_id INT NULL DEFAULT NULL');
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD INDEX idx_products_preview_owner (preview_admin_id, preview_source_product_id)');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'preview_expires_at')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN preview_expires_at DATETIME NULL DEFAULT NULL');
    }
    if (orange_table_exists($pdo, 'product_variants') && !orange_table_has_column($pdo, 'product_variants', 'product_colorway_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_variants ADD COLUMN product_colorway_id INT NULL');
    }
    if (orange_table_exists($pdo, 'product_variants') && !orange_table_has_column($pdo, 'product_variants', 'size_family_size_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_variants ADD COLUMN size_family_size_id INT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'item_code')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN item_code VARCHAR(64) NULL');
    }
    if (orange_table_exists($pdo, 'product_variants') && !orange_table_has_column($pdo, 'product_variants', 'item_code')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_variants ADD COLUMN item_code VARCHAR(64) NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'barcode')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN barcode VARCHAR(64) NULL');
    }
    if (orange_table_exists($pdo, 'product_variants') && orange_table_has_column($pdo, 'product_variants', 'color')) {
        try {
            $colStmt = $pdo->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_variants' AND COLUMN_NAME = 'color' LIMIT 1"
            );
            $colMeta = $colStmt ? $colStmt->fetch(PDO::FETCH_ASSOC) : false;
            $mlColor = $colMeta ? (int) ($colMeta['CHARACTER_MAXIMUM_LENGTH'] ?? 0) : 0;
            if ($mlColor > 0 && $mlColor < 191) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE product_variants MODIFY COLUMN color VARCHAR(191) NULL DEFAULT NULL');
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] product_variants.color widen: ' . $e->getMessage());
            }
        }
    }
    if (orange_table_exists($pdo, 'order_items') && !orange_table_has_column($pdo, 'order_items', 'variant_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE order_items ADD COLUMN variant_id INT NULL');
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'order_source')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE orders ADD COLUMN order_source VARCHAR(32) NOT NULL DEFAULT 'website'"
        );
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'payment_terms')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE orders ADD COLUMN payment_terms VARCHAR(16) NOT NULL DEFAULT 'cash'"
        );
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'customer_email')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN customer_email VARCHAR(255) NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'orders', 'phone')) {
        try {
            $mlStmt = $pdo->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'phone' LIMIT 1"
            );
            $ml = $mlStmt ? (int) $mlStmt->fetchColumn() : 0;
            if ($ml > 0 && $ml < 32) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE orders MODIFY COLUMN phone VARCHAR(32) NOT NULL');
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orders.phone widen: ' . $e->getMessage());
            }
        }
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'phone_country_dial')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN phone_country_dial VARCHAR(8) NULL DEFAULT NULL AFTER customer_name'
        );
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'phone_national')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN phone_national VARCHAR(32) NULL DEFAULT NULL AFTER phone_country_dial'
        );
    }
    if (orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'orders', 'customer_name')) {
        try {
            $mlStmt = $pdo->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'customer_name' LIMIT 1"
            );
            $ml = $mlStmt ? (int) $mlStmt->fetchColumn() : 0;
            if ($ml > 0 && $ml < 255) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE orders MODIFY COLUMN customer_name VARCHAR(255) NOT NULL');
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orders.customer_name widen: ' . $e->getMessage());
            }
        }
    }
    if (orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'orders', 'area')) {
        try {
            $mlStmt = $pdo->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'area' LIMIT 1"
            );
            $ml = $mlStmt ? (int) $mlStmt->fetchColumn() : 0;
            if ($ml > 0 && $ml < 255) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE orders MODIFY COLUMN area VARCHAR(255) NULL DEFAULT NULL');
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orders.area widen: ' . $e->getMessage());
            }
        }
    }
    if (orange_table_exists($pdo, 'size_family_sizes')) {
        if (!orange_table_has_column($pdo, 'size_family_sizes', 'label_fil')) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE size_family_sizes ADD COLUMN label_fil VARCHAR(191) NOT NULL DEFAULT \'\' AFTER label_en');
        }
        if (!orange_table_has_column($pdo, 'size_family_sizes', 'label_hi')) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE size_family_sizes ADD COLUMN label_hi VARCHAR(191) NOT NULL DEFAULT \'\' AFTER label_fil');
        }
    }

    if (orange_table_exists($pdo, 'size_families') && !orange_table_has_column($pdo, 'size_families', 'size_scheme_template_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE size_families ADD COLUMN size_scheme_template_id INT NULL DEFAULT NULL AFTER sizing_category_key'
        );
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE size_families ADD KEY idx_size_families_tpl (size_scheme_template_id)'
        );
    }

    if (orange_table_exists($pdo, 'size_family_sizes') && !orange_table_has_column($pdo, 'size_family_sizes', 'scheme_template_size_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE size_family_sizes ADD COLUMN scheme_template_size_id INT NULL DEFAULT NULL AFTER size_family_id'
        );
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE size_family_sizes ADD KEY idx_size_family_sizes_tpl_sz (scheme_template_size_id)'
        );
    }

    /*
     |--------------------------------------------------------------------------
     | قوالب المقاس ↔ العائلات/الصفوف: تنظيف مراجع يتيمة ثم FK (إن غابت)
     |--------------------------------------------------------------------------
     */
    if (
        orange_table_exists($pdo, 'size_scheme_templates')
        && orange_table_exists($pdo, 'size_scheme_template_sizes')
        && orange_table_exists($pdo, 'size_families')
        && orange_table_has_column($pdo, 'size_families', 'size_scheme_template_id')
        && orange_table_exists($pdo, 'size_family_sizes')
        && orange_table_has_column($pdo, 'size_family_sizes', 'scheme_template_size_id')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE size_family_sizes sfs
             INNER JOIN size_families fam ON fam.id = sfs.size_family_id
             INNER JOIN size_scheme_template_sizes tst ON tst.id = sfs.scheme_template_size_id
             SET sfs.scheme_template_size_id = NULL
             WHERE fam.size_scheme_template_id IS NOT NULL AND fam.size_scheme_template_id > 0
               AND tst.template_id <> fam.size_scheme_template_id'
        );
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE size_families sf
             LEFT JOIN size_scheme_templates t ON t.id = sf.size_scheme_template_id
             SET sf.size_scheme_template_id = NULL
             WHERE sf.size_scheme_template_id IS NOT NULL AND t.id IS NULL'
        );
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE size_family_sizes sfs
             LEFT JOIN size_scheme_template_sizes tst ON tst.id = sfs.scheme_template_size_id
             SET sfs.scheme_template_size_id = NULL
             WHERE sfs.scheme_template_size_id IS NOT NULL AND tst.id IS NULL'
        );
        try {
            $fkNameStmt = $pdo->query(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'size_families'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            );
            $fkNames = $fkNameStmt ? ($fkNameStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
            $fkSet = array_fill_keys(array_map('strtolower', array_map('strval', $fkNames)), true);
            if (!isset($fkSet['orange_fk_sf_scheme_template'])) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE size_families
                     ADD CONSTRAINT orange_fk_sf_scheme_template
                     FOREIGN KEY (size_scheme_template_id) REFERENCES size_scheme_templates (id)
                     ON DELETE SET NULL ON UPDATE CASCADE'
                );
            }
            $fkNameStmt2 = $pdo->query(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'size_family_sizes'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            );
            $fkNames2 = $fkNameStmt2 ? ($fkNameStmt2->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
            $fkSet2 = array_fill_keys(array_map('strtolower', array_map('strval', $fkNames2)), true);
            if (!isset($fkSet2['orange_fk_sfs_scheme_template_size'])) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE size_family_sizes
                     ADD CONSTRAINT orange_fk_sfs_scheme_template_size
                     FOREIGN KEY (scheme_template_size_id) REFERENCES size_scheme_template_sizes (id)
                     ON DELETE SET NULL ON UPDATE CASCADE'
                );
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] size scheme template FK ensure: ' . $e->getMessage());
            }
        }
    }

    // طول القدم لم يعد جزءاً من عائلات/قوالب المقاسات (صار عموداً إرشادياً داخل الدليل الإرشادي للأحذية).
    // إسقاط محروس لمرة واحدة (idempotent): يُحذف العمود إن وُجد، ثم لا يُعاد لأنه أُزيل من CREATE وANY ADD.
    if (orange_table_exists($pdo, 'size_family_sizes') && orange_table_has_column($pdo, 'size_family_sizes', 'foot_length_cm')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE size_family_sizes DROP COLUMN foot_length_cm');
    }
    if (orange_table_exists($pdo, 'size_scheme_template_sizes') && orange_table_has_column($pdo, 'size_scheme_template_sizes', 'foot_length_cm')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE size_scheme_template_sizes DROP COLUMN foot_length_cm');
    }
    if (orange_table_exists($pdo, 'size_families') && !orange_table_has_column($pdo, 'size_families', 'size_scheme_key')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE size_families ADD COLUMN size_scheme_key VARCHAR(64) NOT NULL DEFAULT \'\' AFTER name_en');
    }
    if (orange_table_exists($pdo, 'size_families') && !orange_table_has_column($pdo, 'size_families', 'commercial_kind_key')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE size_families ADD COLUMN commercial_kind_key VARCHAR(32) NOT NULL DEFAULT \'\' AFTER size_scheme_key');
    }
    if (orange_table_exists($pdo, 'size_families') && !orange_table_has_column($pdo, 'size_families', 'sizing_category_key')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE size_families ADD COLUMN sizing_category_key VARCHAR(64) NOT NULL DEFAULT \'\' AFTER commercial_kind_key');
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE size_families ADD INDEX idx_size_families_sizing_scope (commercial_kind_key, sizing_category_key, size_scheme_key)'
        );
    }
    if (orange_table_exists($pdo, 'size_families') && !orange_table_has_column($pdo, 'size_families', 'name_fil')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE size_families ADD COLUMN name_fil VARCHAR(191) NOT NULL DEFAULT \'\' AFTER name_en'
        );
    }
    if (orange_table_exists($pdo, 'size_families') && !orange_table_has_column($pdo, 'size_families', 'name_hi')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE size_families ADD COLUMN name_hi VARCHAR(191) NOT NULL DEFAULT \'\' AFTER name_fil'
        );
    }

    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'name_en')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN name_en VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'name_fil')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN name_fil VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'name_hi')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN name_hi VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'description_en')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN description_en TEXT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'description_fil')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN description_fil TEXT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'description_hi')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN description_hi TEXT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'seo_meta_title_ar')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN seo_meta_title_ar VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'seo_meta_title_en')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN seo_meta_title_en VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'seo_meta_title_fil')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN seo_meta_title_fil VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'seo_meta_title_hi')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN seo_meta_title_hi VARCHAR(191) NOT NULL DEFAULT \'\'');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'seo_meta_description_ar')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN seo_meta_description_ar TEXT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'seo_meta_description_en')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN seo_meta_description_en TEXT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'seo_meta_description_fil')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN seo_meta_description_fil TEXT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'seo_meta_description_hi')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN seo_meta_description_hi TEXT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'sort_order')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN sort_order INT NOT NULL DEFAULT 0');
    }
    if (orange_table_exists($pdo, 'stock_movements') && !orange_table_has_column($pdo, 'stock_movements', 'reference')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE stock_movements ADD COLUMN reference VARCHAR(100) NULL');
    }

    /*
     |--------------------------------------------------------------------------
     | Departments + categories.department_id
     |--------------------------------------------------------------------------
     | المنتج يبقى مربوطاً بالفئة فقط؛ القسم يُستنتج من categories.department_id.
     */
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            slug VARCHAR(191) NOT NULL DEFAULT \'\',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_departments_slug (slug),
            KEY idx_departments_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    /*
     |--------------------------------------------------------------------------
     | Unified catalog taxonomy (ERD Phase A — docs/archive/ORANGE_UNIFIED_TAXONOMY_AND_CATALOG_ERD.txt)
     | Department → catalog_sections → catalog_categories → catalog_subcategories → product_types
     | products.product_type_id = ورقة الشجرة الموحّدة (nullable أثناء الترحيل؛ NOT NULL آلياً عند المتجر الموحّد وجاهزية البيانات الكاملة).
     |--------------------------------------------------------------------------
     */
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS orange_catalog_data_migration_log (
            step_key VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('orange_catalog_data_migration_log');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS catalog_sections (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            department_id INT NOT NULL,
            slug VARCHAR(191) NOT NULL DEFAULT \'\',
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_catalog_sections_dept_slug (department_id, slug),
            KEY idx_catalog_sections_sort (department_id, sort_order),
            KEY idx_catalog_sections_active (department_id, is_active),
            CONSTRAINT fk_catalog_sections_department
                FOREIGN KEY (department_id) REFERENCES departments(id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('catalog_sections');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS catalog_categories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            catalog_section_id INT UNSIGNED NOT NULL,
            slug VARCHAR(191) NOT NULL DEFAULT \'\',
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_catalog_categories_section_slug (catalog_section_id, slug),
            KEY idx_catalog_categories_sort (catalog_section_id, sort_order),
            KEY idx_catalog_categories_active (catalog_section_id, is_active),
            CONSTRAINT fk_catalog_categories_section
                FOREIGN KEY (catalog_section_id) REFERENCES catalog_sections(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('catalog_categories');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS catalog_subcategories (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            catalog_category_id INT UNSIGNED NOT NULL,
            slug VARCHAR(191) NOT NULL DEFAULT \'\',
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_catalog_subcategories_cat_slug (catalog_category_id, slug),
            KEY idx_catalog_subcategories_sort (catalog_category_id, sort_order),
            KEY idx_catalog_subcategories_active (catalog_category_id, is_active),
            CONSTRAINT fk_catalog_subcategories_category
                FOREIGN KEY (catalog_category_id) REFERENCES catalog_categories(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('catalog_subcategories');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS product_types (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            catalog_subcategory_id INT UNSIGNED NOT NULL,
            slug VARCHAR(191) NOT NULL DEFAULT \'\',
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            name_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            name_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            expected_size_scheme_key VARCHAR(64) NOT NULL DEFAULT \'\',
            expected_commercial_kind_key VARCHAR(32) NOT NULL DEFAULT \'\',
            expected_sizing_category_key VARCHAR(64) NOT NULL DEFAULT \'\',
            PRIMARY KEY (id),
            UNIQUE KEY uq_product_types_sub_slug (catalog_subcategory_id, slug),
            KEY idx_product_types_sort (catalog_subcategory_id, sort_order),
            KEY idx_product_types_active (catalog_subcategory_id, is_active),
            KEY idx_product_types_expected_scheme (expected_size_scheme_key),
            KEY idx_product_types_expected_sizing (expected_commercial_kind_key, expected_sizing_category_key),
            CONSTRAINT fk_product_types_catalog_subcategory
                FOREIGN KEY (catalog_subcategory_id) REFERENCES catalog_subcategories(id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('product_types');

    if (orange_table_exists($pdo, 'product_types') && !orange_table_has_column($pdo, 'product_types', 'expected_size_scheme_key')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_types ADD COLUMN expected_size_scheme_key VARCHAR(64) NOT NULL DEFAULT \'\' AFTER created_at');
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_types ADD INDEX idx_product_types_expected_scheme (expected_size_scheme_key)');
        orange_schema_invalidate_column_check('product_types', 'expected_size_scheme_key');
    }

    if (orange_table_exists($pdo, 'product_types') && !orange_table_has_column($pdo, 'product_types', 'expected_commercial_kind_key')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE product_types ADD COLUMN expected_commercial_kind_key VARCHAR(32) NOT NULL DEFAULT \'\' AFTER expected_size_scheme_key'
        );
        orange_schema_invalidate_column_check('product_types', 'expected_commercial_kind_key');
    }
    if (orange_table_exists($pdo, 'product_types') && !orange_table_has_column($pdo, 'product_types', 'expected_sizing_category_key')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE product_types ADD COLUMN expected_sizing_category_key VARCHAR(64) NOT NULL DEFAULT \'\' AFTER expected_commercial_kind_key'
        );
        orange_schema_invalidate_column_check('product_types', 'expected_sizing_category_key');
    }
    if (orange_table_exists($pdo, 'product_types') && !orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE product_types ADD COLUMN default_advisory_sizing_guide_id INT NULL DEFAULT NULL AFTER expected_sizing_category_key'
        );
        orange_schema_invalidate_column_check('product_types', 'default_advisory_sizing_guide_id');
    }
    if (orange_table_exists($pdo, 'product_types')
        && orange_table_has_column($pdo, 'product_types', 'expected_commercial_kind_key')
        && orange_table_has_column($pdo, 'product_types', 'expected_sizing_category_key')
    ) {
        try {
            $chkIdx = $pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_types' AND INDEX_NAME = 'idx_product_types_expected_sizing'"
            );
            if ($chkIdx && (int) $chkIdx->fetchColumn() === 0) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE product_types ADD INDEX idx_product_types_expected_sizing (expected_commercial_kind_key, expected_sizing_category_key)'
                );
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'product_type_id')) {
        $afterLegacy = orange_table_has_column($pdo, 'products', 'subcategory_id')
            ? ' AFTER subcategory_id'
            : (orange_table_has_column($pdo, 'products', 'category_id') ? ' AFTER category_id' : '');
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN product_type_id INT UNSIGNED NULL DEFAULT NULL' . $afterLegacy);
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD INDEX idx_products_product_type (product_type_id)');
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE products ADD CONSTRAINT fk_products_product_type
                FOREIGN KEY (product_type_id) REFERENCES product_types(id)
                ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        orange_schema_invalidate_column_check('products', 'product_type_id');
    }

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS catalog_attributes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            attribute_key VARCHAR(80) NOT NULL DEFAULT \'\',
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            label_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            label_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            input_kind VARCHAR(24) NOT NULL DEFAULT \'text_short\',
            is_filterable TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_catalog_attributes_key (attribute_key),
            KEY idx_catalog_attributes_sort (sort_order),
            KEY idx_catalog_attributes_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('catalog_attributes');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS product_attribute_values (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id INT NOT NULL,
            catalog_attribute_id INT UNSIGNED NOT NULL,
            value_raw VARCHAR(767) NOT NULL DEFAULT \'\',
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pav_prod_attr (product_id, catalog_attribute_id),
            KEY idx_pav_product (product_id),
            KEY idx_pav_attr (catalog_attribute_id),
            CONSTRAINT fk_pav_catalog_attribute
                FOREIGN KEY (catalog_attribute_id) REFERENCES catalog_attributes(id)
                ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_pav_product
                FOREIGN KEY (product_id) REFERENCES products(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('product_attribute_values');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS catalog_attribute_options (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            catalog_attribute_id INT UNSIGNED NOT NULL,
            option_key VARCHAR(80) NOT NULL DEFAULT \'\',
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            label_fil VARCHAR(191) NOT NULL DEFAULT \'\',
            label_hi VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_catalog_attr_opt_attr_key (catalog_attribute_id, option_key),
            KEY idx_catalog_attr_opt_sort (catalog_attribute_id, sort_order),
            CONSTRAINT fk_catalog_attr_opt_attr
                FOREIGN KEY (catalog_attribute_id) REFERENCES catalog_attributes(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('catalog_attribute_options');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS commercial_kind_dictionary (
            kind_key VARCHAR(32) NOT NULL,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (kind_key),
            KEY idx_ckd_sort (sort_order),
            KEY idx_ckd_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('commercial_kind_dictionary');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS sizing_category_dictionary (
            commercial_kind_key VARCHAR(32) NOT NULL,
            category_key VARCHAR(64) NOT NULL,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (commercial_kind_key, category_key),
            KEY idx_scd_kind_sort (commercial_kind_key, sort_order),
            CONSTRAINT fk_scd_commercial_kind
                FOREIGN KEY (commercial_kind_key) REFERENCES commercial_kind_dictionary(kind_key)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('sizing_category_dictionary');

    /*
     |--------------------------------------------------------------------------
     | كود الحساب في الشجرة + ربط الحسابات الأساسية للقيود التلقائية
     |--------------------------------------------------------------------------
     */
    if (!orange_table_exists($pdo, 'accounts')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(191) NOT NULL,
                code VARCHAR(64) NULL,
                parent_id INT NULL,
                is_group TINYINT(1) NOT NULL DEFAULT 0,
                name_en VARCHAR(191) NOT NULL DEFAULT \'\',
                is_suspended TINYINT(1) NOT NULL DEFAULT 0,
                normal_balance VARCHAR(16) NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_accounts_code (code),
                KEY idx_accounts_parent_id (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'code')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE accounts ADD COLUMN code VARCHAR(64) NULL');
        orange_catalog_safe_exec($pdo, 'CREATE UNIQUE INDEX uq_accounts_code ON accounts (code)');
    }

    orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);

    /*
     |--------------------------------------------------------------------------
     | السنوات المالية — إغلاق سنة / فتح سنة جديدة / ربط القيود
     |--------------------------------------------------------------------------
     */
    if (!orange_table_exists($pdo, 'fiscal_years')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE fiscal_years (
                id INT AUTO_INCREMENT PRIMARY KEY,
                label_ar VARCHAR(160) NOT NULL DEFAULT \'\',
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                is_closed TINYINT(1) NOT NULL DEFAULT 0,
                closed_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_fiscal_years_range (start_date, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'journal_entries') && !orange_table_has_column($pdo, 'journal_entries', 'fiscal_year_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE journal_entries ADD COLUMN fiscal_year_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_journal_entries_fiscal_year ON journal_entries (fiscal_year_id)');
    }

    static $fiscalYearsSeeded = false;
    if (!$fiscalYearsSeeded && orange_table_exists($pdo, 'fiscal_years')) {
        $fiscalYearsSeeded = true;
        try {
            $cnt = (int) $pdo->query('SELECT COUNT(*) FROM fiscal_years')->fetchColumn();
            if ($cnt === 0) {
                $y = (int) date('Y');
                $ins = $pdo->prepare('INSERT INTO fiscal_years (label_ar, start_date, end_date, is_closed) VALUES (?, ?, ?, 0)');
                $ins->execute(['سنة مالية ' . $y, sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)]);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] fiscal_years seed: ' . $e->getMessage());
            }
        }
    }

    static $fiscalYearBackfillDone = false;
    if (
        !$fiscalYearBackfillDone
        && orange_table_exists($pdo, 'journal_entries')
        && orange_table_has_column($pdo, 'journal_entries', 'fiscal_year_id')
        && orange_table_exists($pdo, 'fiscal_years')
    ) {
        $fiscalYearBackfillDone = true;
        try {
            $nulls = (int) $pdo->query('SELECT COUNT(*) FROM journal_entries WHERE fiscal_year_id IS NULL')->fetchColumn();
            if ($nulls > 0) {
                orange_catalog_safe_exec(
                    $pdo,
                    'UPDATE journal_entries je
                     INNER JOIN fiscal_years fy ON DATE(je.date) BETWEEN fy.start_date AND fy.end_date
                     SET je.fiscal_year_id = fy.id
                     WHERE je.fiscal_year_id IS NULL'
                );
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] fiscal_year backfill: ' . $e->getMessage());
            }
        }
    }

    /*
     |--------------------------------------------------------------------------
     | سندات متعددة الأسطر (journal_vouchers / journal_lines)
     |--------------------------------------------------------------------------
     | تصنيف قائمة الدخل يُشتق من كود/ترتيب الجذر في includes/account_tree.php (لا عمود account_class).
     */
    if (orange_table_exists($pdo, 'accounts') && orange_table_has_column($pdo, 'accounts', 'account_class')) {
        try {
            $pdo->exec('ALTER TABLE accounts DROP COLUMN account_class');
            orange_schema_invalidate_column_check('accounts', 'account_class');
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] DROP account_class: ' . $e->getMessage());
            }
        }
    }

    if (!orange_table_exists($pdo, 'journal_vouchers')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE journal_vouchers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_date DATETIME NOT NULL,
                reference VARCHAR(100) NULL,
                description TEXT NULL,
                entry_type VARCHAR(64) NOT NULL DEFAULT \'general\',
                fiscal_year_id INT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_jv_reference (reference),
                INDEX idx_jv_fiscal_year (fiscal_year_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'journal_vouchers')
        && !orange_table_has_column($pdo, 'journal_vouchers', 'document_entered_at')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE journal_vouchers ADD COLUMN document_entered_at DATETIME NULL AFTER voucher_date'
        );
        orange_schema_invalidate_column_check('journal_vouchers', 'document_entered_at');
        try {
            $pdo->exec(
                'UPDATE journal_vouchers SET document_entered_at = COALESCE(created_at, voucher_date) WHERE document_entered_at IS NULL'
            );
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] journal_vouchers document_entered_at backfill: ' . $e->getMessage());
            }
        }
    }

    if (orange_table_exists($pdo, 'journal_vouchers') && !orange_table_has_column($pdo, 'journal_vouchers', 'journal_type_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE journal_vouchers ADD COLUMN journal_type_id INT NULL AFTER fiscal_year_id'
        );
        orange_schema_invalidate_column_check('journal_vouchers', 'journal_type_id');
    }
    if (orange_table_exists($pdo, 'journal_vouchers')
        && orange_table_has_column($pdo, 'journal_vouchers', 'journal_type_id')
        && !orange_table_has_column($pdo, 'journal_vouchers', 'journal_serial_bucket')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE journal_vouchers ADD COLUMN journal_serial_bucket VARCHAR(64) NOT NULL DEFAULT '' AFTER journal_type_id"
        );
        orange_schema_invalidate_column_check('journal_vouchers', 'journal_serial_bucket');
    }
    if (orange_table_exists($pdo, 'journal_vouchers')
        && orange_table_has_column($pdo, 'journal_vouchers', 'journal_serial_bucket')
        && !orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE journal_vouchers ADD COLUMN voucher_serial INT UNSIGNED NOT NULL DEFAULT 0 AFTER journal_serial_bucket'
        );
        orange_schema_invalidate_column_check('journal_vouchers', 'voucher_serial');
    }

    if (!orange_table_exists($pdo, 'journal_lines')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE journal_lines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                voucher_id INT NOT NULL,
                line_no SMALLINT NOT NULL DEFAULT 0,
                account_id INT NOT NULL,
                debit DECIMAL(18,4) NOT NULL DEFAULT 0,
                credit DECIMAL(18,4) NOT NULL DEFAULT 0,
                memo VARCHAR(255) NULL,
                INDEX idx_jl_voucher (voucher_id),
                INDEX idx_jl_account (account_id),
                CONSTRAINT orange_fk_jl_voucher FOREIGN KEY (voucher_id) REFERENCES journal_vouchers (id) ON DELETE CASCADE,
                CONSTRAINT orange_fk_jl_account FOREIGN KEY (account_id) REFERENCES accounts (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'orange_gl_pending_movements')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_gl_pending_movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reference VARCHAR(100) NOT NULL,
                source_label VARCHAR(128) NOT NULL DEFAULT \'\',
                movement_at DATETIME NOT NULL,
                voucher_date DATETIME NOT NULL,
                account_debit INT NOT NULL,
                account_credit INT NOT NULL,
                amount DECIMAL(18,4) NOT NULL,
                description VARCHAR(512) NOT NULL,
                entry_type VARCHAR(64) NOT NULL DEFAULT \'general\',
                status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                journal_voucher_id INT NULL,
                after_post_json TEXT NULL,
                multi_line TINYINT(1) NOT NULL DEFAULT 0,
                voucher_lines_json TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                posted_at DATETIME NULL,
                UNIQUE KEY uq_gl_pending_ref (reference),
                KEY idx_gl_pending_status (status),
                KEY idx_gl_pending_movement_at (movement_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')
        && !orange_table_has_column($pdo, 'orange_gl_pending_movements', 'multi_line')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_pending_movements ADD COLUMN multi_line TINYINT(1) NOT NULL DEFAULT 0'
        );
    }
    if (orange_table_exists($pdo, 'orange_gl_pending_movements')
        && !orange_table_has_column($pdo, 'orange_gl_pending_movements', 'voucher_lines_json')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_pending_movements ADD COLUMN voucher_lines_json TEXT NULL'
        );
    }

    if (orange_table_exists($pdo, 'orange_gl_pending_movements')
        && !orange_table_has_column($pdo, 'orange_gl_pending_movements', 'journal_type_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_pending_movements ADD COLUMN journal_type_id INT NULL AFTER entry_type'
        );
        orange_schema_invalidate_column_check('orange_gl_pending_movements', 'journal_type_id');
    }

    if (!orange_table_exists($pdo, 'journal_types')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE journal_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL,
                name_ar VARCHAR(255) NOT NULL DEFAULT \'\',
                name_en VARCHAR(255) NOT NULL DEFAULT \'\',
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_journal_types_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'journal_types')) {
        require_once __DIR__ . '/journal_types.php';
        try {
            $jtDefaultCid = orange_countries_default_id($pdo);
            if ($jtDefaultCid > 0 && orange_journal_types_has_country_column($pdo)) {
                orange_journal_types_sync_canonical_defaults($pdo, $jtDefaultCid);
            } else {
                orange_journal_types_sync_canonical_defaults($pdo);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] journal_types sync: ' . $e->getMessage());
            }
        }

        require_once __DIR__ . '/journal_voucher.php';
        try {
            orange_journal_vouchers_backfill_serial_numbers($pdo);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] journal_vouchers serial backfill (post-merge): ' . $e->getMessage());
            }
        }
        if (orange_table_exists($pdo, 'journal_vouchers')
            && orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')) {
            try {
                $pdo->exec(
                    'CREATE UNIQUE INDEX uq_jv_fy_bucket_serial ON journal_vouchers (fiscal_year_id, journal_serial_bucket, voucher_serial)'
                );
            } catch (Throwable $e) {
                // قد يكون موجوداً مسبقاً.
                if (function_exists('error_log')) {
                    error_log('[orange] CREATE uq_jv_fy_bucket_serial: ' . $e->getMessage());
                }
            }
        }
    }

    orange_catalog_ensure_orange_gl_journal_type_rules($pdo);

    /*
     |--------------------------------------------------------------------------
     | ترحيل journal_entries (تراثي) → journal_vouchers / journal_lines
     |--------------------------------------------------------------------------
     | القرار: القيود الحديثة تُخزَّن في journal_vouchers + journal_lines. جدول
     | journal_entries (مدين/دائن في صف واحد) تراثي. قواعد أُنشئت من
     | mysql-create-orange-database-full.sql الحالي لا تضم هذا الجدول — الكتلة تُستبعد
     | تلقائياً عبر orange_table_exists. إن وُجد journal_entries وكان journal_lines
     | فارغاً وفيه صفوف صالحة، يُرحَّل هنا ثم يُفرَّغ journal_entries.
     | صفحة الأدمن journal_entries.php تستخدم السندات الحديثة رغم اسم الملف.
     |--------------------------------------------------------------------------
     */
    static $journalLegacyMigrated = false;
    if (
        !$journalLegacyMigrated
        && orange_table_exists($pdo, 'journal_entries')
        && orange_table_exists($pdo, 'journal_vouchers')
        && orange_table_exists($pdo, 'journal_lines')
    ) {
        $journalLegacyMigrated = true;
        try {
            $lc = (int) $pdo->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
            $ec = (int) $pdo->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
            if ($lc === 0 && $ec > 0) {
                $hasJeEt = orange_table_has_column($pdo, 'journal_entries', 'entry_type');
                $hasJeFy = orange_table_has_column($pdo, 'journal_entries', 'fiscal_year_id');
                $rows = $pdo->query('SELECT * FROM journal_entries ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
                $vIns = $pdo->prepare(
                    'INSERT INTO journal_vouchers (voucher_date, reference, description, entry_type, fiscal_year_id)
                     VALUES (?,?,?,?,?)'
                );
                $lIns = $pdo->prepare(
                    'INSERT INTO journal_lines (voucher_id, line_no, account_id, debit, credit, memo) VALUES (?,?,?,?,?,?)'
                );
                $migrated = 0;
                $pdo->beginTransaction();
                try {
                    foreach ($rows as $je) {
                        $d = (string) ($je['date'] ?? date('Y-m-d H:i:s'));
                        $ref = isset($je['reference']) ? (string) $je['reference'] : null;
                        if ($ref === '') {
                            $ref = null;
                        }
                        $desc = (string) ($je['description'] ?? '');
                        $et = ($hasJeEt && isset($je['entry_type'])) ? (string) $je['entry_type'] : 'migrated';
                        if ($et === '') {
                            $et = 'migrated';
                        }
                        $fy = ($hasJeFy && isset($je['fiscal_year_id'])) ? (int) $je['fiscal_year_id'] : null;
                        if ($fy <= 0) {
                            $fy = null;
                        }
                        $amt = (float) ($je['amount'] ?? 0);
                        $ad = (int) ($je['account_debit'] ?? 0);
                        $ac = (int) ($je['account_credit'] ?? 0);
                        if ($ad <= 0 || $ac <= 0 || $amt <= 0) {
                            continue;
                        }
                        $vIns->execute([$d, $ref, $desc, $et, $fy]);
                        $vid = (int) $pdo->lastInsertId();
                        $lIns->execute([$vid, 1, $ad, $amt, 0, null]);
                        $lIns->execute([$vid, 2, $ac, 0, $amt, null]);
                        ++$migrated;
                    }
                    if ($migrated > 0) {
                        $pdo->exec('DELETE FROM journal_entries');
                    }
                    $pdo->commit();
                    if ($migrated > 0 && orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')) {
                        require_once __DIR__ . '/journal_voucher.php';
                        try {
                            orange_journal_vouchers_backfill_serial_numbers($pdo);
                        } catch (Throwable $eSerial) {
                            if (function_exists('error_log')) {
                                error_log('[orange] journal legacy migrate serial backfill: ' . $eSerial->getMessage());
                            }
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] journal legacy migrate: ' . $e->getMessage());
            }
        }
    }

    /*
     |--------------------------------------------------------------------------
     | العملاء + الذمم الفرعية (ذمم مدينة / دائنة لكل طرف)
     |--------------------------------------------------------------------------
     */
    if (!orange_table_exists($pdo, 'customers')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE customers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NULL,
                name_ar VARCHAR(255) NOT NULL DEFAULT \'\',
                phone VARCHAR(32) NOT NULL DEFAULT \'\',
                area VARCHAR(255) NOT NULL DEFAULT \'\',
                address VARCHAR(2000) NOT NULL DEFAULT \'\',
                email VARCHAR(255) NULL,
                notes TEXT NULL,
                credit_limit DECIMAL(18,4) NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_customers_phone (phone),
                UNIQUE KEY uq_customers_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'credit_limit')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE customers ADD COLUMN credit_limit DECIMAL(18,4) NULL DEFAULT NULL');
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'code')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE customers ADD COLUMN code VARCHAR(32) NULL');
        orange_catalog_safe_exec($pdo, 'CREATE UNIQUE INDEX uq_customers_code ON customers (code)');
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'area')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE customers ADD COLUMN area VARCHAR(160) NOT NULL DEFAULT \'\'');
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'address')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE customers ADD COLUMN address VARCHAR(600) NOT NULL DEFAULT \'\'');
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'email')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE customers ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL');
    }
    if (orange_table_exists($pdo, 'customers') && orange_table_has_column($pdo, 'customers', 'notes')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE customers MODIFY COLUMN notes TEXT NULL');
    }
    if (orange_table_exists($pdo, 'customers') && orange_table_has_column($pdo, 'customers', 'name_ar')) {
        try {
            $mlStmt = $pdo->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'name_ar' LIMIT 1"
            );
            $ml = $mlStmt ? (int) $mlStmt->fetchColumn() : 0;
            if ($ml > 0 && $ml < 255) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE customers MODIFY COLUMN name_ar VARCHAR(255) NOT NULL');
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] customers.name_ar widen: ' . $e->getMessage());
            }
        }
    }
    if (orange_table_exists($pdo, 'customers') && orange_table_has_column($pdo, 'customers', 'area')) {
        try {
            $mlStmt = $pdo->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'area' LIMIT 1"
            );
            $ml = $mlStmt ? (int) $mlStmt->fetchColumn() : 0;
            if ($ml > 0 && $ml < 255) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE customers MODIFY COLUMN area VARCHAR(255) NOT NULL');
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] customers.area widen: ' . $e->getMessage());
            }
        }
    }
    if (orange_table_exists($pdo, 'customers') && orange_table_has_column($pdo, 'customers', 'address')) {
        try {
            $mlStmt = $pdo->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'address' LIMIT 1"
            );
            $ml = $mlStmt ? (int) $mlStmt->fetchColumn() : 0;
            if ($ml > 0 && $ml < 2000) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE customers MODIFY COLUMN address VARCHAR(2000) NOT NULL');
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] customers.address widen: ' . $e->getMessage());
            }
        }
    }
    if (orange_table_exists($pdo, 'customers') && orange_table_has_column($pdo, 'customers', 'phone')) {
        try {
            $mlStmt = $pdo->query(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'phone' LIMIT 1"
            );
            $ml = $mlStmt ? (int) $mlStmt->fetchColumn() : 0;
            if ($ml > 0 && $ml < 32) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE customers MODIFY COLUMN phone VARCHAR(32) NOT NULL');
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] customers.phone widen: ' . $e->getMessage());
            }
        }
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'phone_country_dial')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE customers ADD COLUMN phone_country_dial VARCHAR(8) NULL DEFAULT NULL AFTER name_ar'
        );
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'phone_national')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE customers ADD COLUMN phone_national VARCHAR(32) NULL DEFAULT NULL AFTER phone_country_dial'
        );
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'delivery_area_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE customers ADD COLUMN delivery_area_id INT UNSIGNED NULL DEFAULT NULL AFTER area'
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_customers_delivery_area ON customers (delivery_area_id)'
        );
    }
    // س15 + تطوير شاشة العملاء الاحترافية: حالة العميل، سبب الحظر، مرفقات.
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'status')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE customers ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active'"
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_customers_status ON customers (status)'
        );
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'block_reason')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE customers ADD COLUMN block_reason VARCHAR(255) NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'attachments_json')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE customers ADD COLUMN attachments_json TEXT NULL DEFAULT NULL'
        );
    }
    // س15: الرقم المدني (Civil ID / Iqama) — اختياري لكن فريد إذا أدخل. UNIQUE في MySQL يقبل تعدد NULL.
    if (orange_table_exists($pdo, 'customers') && !orange_table_has_column($pdo, 'customers', 'civil_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE customers ADD COLUMN civil_id VARCHAR(20) NULL DEFAULT NULL'
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE UNIQUE INDEX uq_customers_civil_id ON customers (civil_id)'
        );
    }

    orange_catalog_ensure_suppliers_schema($pdo);

    if (orange_table_exists($pdo, 'channels') && orange_table_has_column($pdo, 'channels', 'logo')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE channels DROP COLUMN logo');
    }

    /*
     |--------------------------------------------------------------------------
     | مردود المشتريات + مردود المبيعات (ربط بالمورد/العميل ومستندات الشراء/البيع)
     |--------------------------------------------------------------------------
     */
    if (!orange_table_exists($pdo, 'purchase_returns')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE purchase_returns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                return_number VARCHAR(32) NOT NULL,
                purchase_id INT NULL,
                supplier_id INT NULL,
                type VARCHAR(16) NOT NULL DEFAULT \'credit\',
                total DECIMAL(18,4) NOT NULL DEFAULT 0,
                notes VARCHAR(512) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_purchase_returns_number (return_number),
                KEY idx_purchase_returns_supplier (supplier_id),
                KEY idx_purchase_returns_purchase (purchase_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (!orange_table_exists($pdo, 'purchase_return_items')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE purchase_return_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                purchase_return_id INT NOT NULL,
                product_id INT NOT NULL,
                variant_id INT NULL,
                qty INT NOT NULL,
                cost DECIMAL(18,4) NOT NULL DEFAULT 0,
                KEY idx_pri_return (purchase_return_id),
                KEY idx_pri_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (!orange_table_exists($pdo, 'sales_returns')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE sales_returns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                return_number VARCHAR(32) NOT NULL,
                order_id INT NULL,
                customer_id INT NULL,
                channel_id INT NULL,
                type VARCHAR(16) NOT NULL DEFAULT \'credit\',
                total DECIMAL(18,4) NOT NULL DEFAULT 0,
                notes VARCHAR(512) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_sales_returns_number (return_number),
                KEY idx_sales_returns_order (order_id),
                KEY idx_sales_returns_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (!orange_table_exists($pdo, 'sales_return_items')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE sales_return_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sales_return_id INT NOT NULL,
                product_id INT NOT NULL,
                variant_id INT NULL,
                qty INT NOT NULL,
                price DECIMAL(18,4) NOT NULL DEFAULT 0,
                line_discount DECIMAL(18,4) NOT NULL DEFAULT 0,
                KEY idx_sri_return (sales_return_id),
                KEY idx_sri_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'sales_returns') && !orange_table_has_column($pdo, 'sales_returns', 'channel_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE sales_returns ADD COLUMN channel_id INT NULL AFTER customer_id'
        );
    }

    /*
     |--------------------------------------------------------------------------
     | طابور استلام طلبات الواجهة (FIFO) — تسلسل كتابة الطلبات وتسجيل العملاء
     |--------------------------------------------------------------------------
     */
    if (!orange_table_exists($pdo, 'order_intake_queue')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE order_intake_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_token CHAR(32) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                payload_json MEDIUMTEXT NOT NULL,
                order_id INT NULL,
                order_number VARCHAR(64) NULL,
                whatsapp_number VARCHAR(40) NULL,
                whatsapp_url TEXT NULL,
                error_message VARCHAR(512) NULL,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_order_intake_token (public_token),
                KEY idx_order_intake_status_id (status, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /*
     |--------------------------------------------------------------------------
     | ربط المنتجات بالقنوات — يُستخدم من includes/product_channels.php وواجهات المنتجات
     |--------------------------------------------------------------------------
     */
    if (
        orange_table_exists($pdo, 'products')
        && orange_table_exists($pdo, 'channels')
        && !orange_table_exists($pdo, 'product_channels')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE product_channels (
                product_id INT NOT NULL,
                channel_id INT NOT NULL,
                PRIMARY KEY (product_id, channel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'product_channels')
        && orange_table_exists($pdo, 'products')
        && orange_table_exists($pdo, 'channels')
    ) {
        try {
            $fkNameStmt = $pdo->query(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'product_channels'
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
            );
            $fkNames = $fkNameStmt ? ($fkNameStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
            $fkSet = array_fill_keys(array_map('strtolower', array_map('strval', $fkNames)), true);
            if (!isset($fkSet['orange_fk_pc_product'])) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE product_channels
                     ADD CONSTRAINT orange_fk_pc_product
                     FOREIGN KEY (product_id) REFERENCES products (id)
                     ON DELETE CASCADE ON UPDATE CASCADE'
                );
            }
            if (!isset($fkSet['orange_fk_pc_channel'])) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE product_channels
                     ADD CONSTRAINT orange_fk_pc_channel
                     FOREIGN KEY (channel_id) REFERENCES channels (id)
                     ON DELETE CASCADE ON UPDATE CASCADE'
                );
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] product_channels FK check: ' . $e->getMessage());
            }
        }
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'customer_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN customer_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_orders_customer_id ON orders (customer_id)');
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'storefront_account_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN storefront_account_id INT UNSIGNED NULL DEFAULT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_orders_storefront_account_id ON orders (storefront_account_id)');
    }

    if (!orange_table_exists($pdo, 'party_subledger') && orange_table_exists($pdo, 'journal_vouchers')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE party_subledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_kind VARCHAR(20) NOT NULL,
                party_id INT NOT NULL,
                voucher_id INT NOT NULL,
                debit DECIMAL(18,4) NOT NULL DEFAULT 0,
                credit DECIMAL(18,4) NOT NULL DEFAULT 0,
                ref_type VARCHAR(32) NULL,
                ref_id INT NULL,
                memo VARCHAR(255) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ps_party (party_kind, party_id),
                KEY idx_ps_voucher (voucher_id),
                CONSTRAINT orange_fk_ps_voucher FOREIGN KEY (voucher_id) REFERENCES journal_vouchers (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'party_subledger_allocations') && orange_table_exists($pdo, 'journal_vouchers')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE party_subledger_allocations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                party_kind VARCHAR(20) NOT NULL,
                party_id INT NOT NULL,
                payment_voucher_id INT NOT NULL,
                target_ref_type VARCHAR(32) NOT NULL,
                target_ref_id INT NOT NULL,
                amount DECIMAL(18,4) NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_psa_party (party_kind, party_id),
                KEY idx_psa_payment (payment_voucher_id),
                KEY idx_psa_target (target_ref_type, target_ref_id),
                CONSTRAINT orange_fk_psa_voucher FOREIGN KEY (payment_voucher_id) REFERENCES journal_vouchers (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'parent_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE accounts ADD COLUMN parent_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_accounts_parent_id ON accounts (parent_id)');
    }
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'is_group')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE accounts ADD COLUMN is_group TINYINT(1) NOT NULL DEFAULT 0'
        );
    }
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'name_en')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE accounts ADD COLUMN name_en VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'is_suspended')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE accounts ADD COLUMN is_suspended TINYINT(1) NOT NULL DEFAULT 0'
        );
    }
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'normal_balance')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE accounts ADD COLUMN normal_balance VARCHAR(16) NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'accounts') && orange_table_has_column($pdo, 'accounts', 'normal_balance')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE accounts MODIFY COLUMN normal_balance VARCHAR(16) NULL DEFAULT NULL'
        );
        if (orange_table_has_column($pdo, 'accounts', 'is_group')) {
            orange_catalog_safe_exec($pdo, 'UPDATE accounts SET normal_balance = NULL WHERE is_group = 1');
        }
    }
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'account_type')) {
        orange_catalog_safe_exec($pdo, "ALTER TABLE accounts ADD COLUMN account_type VARCHAR(32) NULL DEFAULT NULL AFTER normal_balance");
    }
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'report_section')) {
        orange_catalog_safe_exec($pdo, "ALTER TABLE accounts ADD COLUMN report_section VARCHAR(32) NULL DEFAULT NULL AFTER account_type");
    }
    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'report_line')) {
        orange_catalog_safe_exec($pdo, "ALTER TABLE accounts ADD COLUMN report_line VARCHAR(128) NULL DEFAULT NULL AFTER report_section");
    }

    if (orange_table_exists($pdo, 'accounts')) {
        require_once __DIR__ . '/report_line_master.php';
        orange_report_line_master_ensure_table($pdo);
        orange_report_line_master_seed_defaults($pdo);
        if (!orange_table_has_column($pdo, 'accounts', 'report_line_id')) {
            if (orange_table_has_column($pdo, 'accounts', 'report_section')) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE accounts ADD COLUMN report_line_id INT UNSIGNED NULL DEFAULT NULL AFTER report_section'
                );
            } else {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE accounts ADD COLUMN report_line_id INT UNSIGNED NULL DEFAULT NULL');
            }
        }
        orange_report_line_migrate_legacy_text_column($pdo);
    }

    if (orange_table_exists($pdo, 'accounts') && !orange_table_has_column($pdo, 'accounts', 'cashflow_section')) {
        $afterCol = 'report_section';
        if (orange_table_has_column($pdo, 'accounts', 'report_line_id')) {
            $afterCol = 'report_line_id';
        } elseif (orange_table_has_column($pdo, 'accounts', 'report_line')) {
            $afterCol = 'report_line';
        }
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE accounts ADD COLUMN cashflow_section VARCHAR(32) NOT NULL DEFAULT 'none' AFTER {$afterCol}"
        );
    }

    static $accountsDefaultSeeded = false;
    if (!$accountsDefaultSeeded && orange_table_exists($pdo, 'accounts')) {
        $accountsDefaultSeeded = true;
        orange_catalog_seed_default_accounts_if_empty($pdo);
    }

    if (orange_table_exists($pdo, 'admins') && !orange_table_has_column($pdo, 'admins', 'is_superuser')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE admins ADD COLUMN is_superuser TINYINT(1) NOT NULL DEFAULT 0'
        );
        orange_catalog_safe_exec($pdo, 'UPDATE admins SET is_superuser = 1');
    }

    if (!orange_table_exists($pdo, 'admin_permissions')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE admin_permissions (
                admin_id INT NOT NULL,
                resource_key VARCHAR(80) NOT NULL,
                can_view TINYINT(1) NOT NULL DEFAULT 0,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                can_delete TINYINT(1) NOT NULL DEFAULT 0,
                can_lock TINYINT(1) NOT NULL DEFAULT 0,
                can_unlock TINYINT(1) NOT NULL DEFAULT 0,
                can_print TINYINT(1) NOT NULL DEFAULT 0,
                can_export TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (admin_id, resource_key),
                KEY idx_admin_permissions_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'admin_permissions') && !orange_table_has_column($pdo, 'admin_permissions', 'can_lock')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE admin_permissions ADD COLUMN can_lock TINYINT(1) NOT NULL DEFAULT 0 AFTER can_delete');
        orange_schema_invalidate_column_check('admin_permissions', 'can_lock');
    }
    if (orange_table_exists($pdo, 'admin_permissions') && !orange_table_has_column($pdo, 'admin_permissions', 'can_unlock')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE admin_permissions ADD COLUMN can_unlock TINYINT(1) NOT NULL DEFAULT 0 AFTER can_lock');
        orange_schema_invalidate_column_check('admin_permissions', 'can_unlock');
    }
    if (orange_table_exists($pdo, 'admin_permissions') && !orange_table_has_column($pdo, 'admin_permissions', 'can_print')) {
        $afterPrint = orange_table_has_column($pdo, 'admin_permissions', 'can_unlock') ? 'can_unlock' : 'can_delete';
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE admin_permissions ADD COLUMN can_print TINYINT(1) NOT NULL DEFAULT 0 AFTER {$afterPrint}"
        );
        orange_schema_invalidate_column_check('admin_permissions', 'can_print');
    }
    if (orange_table_exists($pdo, 'admin_permissions') && !orange_table_has_column($pdo, 'admin_permissions', 'can_export')) {
        $afterExport = orange_table_has_column($pdo, 'admin_permissions', 'can_print') ? 'can_print' : 'can_delete';
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE admin_permissions ADD COLUMN can_export TINYINT(1) NOT NULL DEFAULT 0 AFTER {$afterExport}"
        );
        orange_schema_invalidate_column_check('admin_permissions', 'can_export');
    }

    if (!orange_table_exists($pdo, 'document_sequences')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE document_sequences (
                scope VARCHAR(64) NOT NULL,
                last_value BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'purchase_items') && !orange_table_has_column($pdo, 'purchase_items', 'variant_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE purchase_items ADD COLUMN variant_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_purchase_items_variant ON purchase_items (variant_id)');
    }

    if (orange_table_exists($pdo, 'purchase_items') && !orange_table_has_column($pdo, 'purchase_items', 'qty_received')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE purchase_items ADD COLUMN qty_received INT NOT NULL DEFAULT 0'
        );
        orange_catalog_safe_exec($pdo, 'UPDATE purchase_items SET qty_received = qty');
    }

    if (orange_table_exists($pdo, 'purchase_items') && !orange_table_has_column($pdo, 'purchase_items', 'discount_raw')) {
        orange_catalog_safe_exec($pdo, "ALTER TABLE purchase_items ADD COLUMN discount_raw VARCHAR(32) NOT NULL DEFAULT ''");
        orange_catalog_safe_exec($pdo, 'ALTER TABLE purchase_items ADD COLUMN discount_amount DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    if (orange_table_exists($pdo, 'purchases') && !orange_table_has_column($pdo, 'purchases', 'invoice_discount_raw')) {
        orange_catalog_safe_exec($pdo, "ALTER TABLE purchases ADD COLUMN invoice_discount_raw VARCHAR(32) NOT NULL DEFAULT ''");
        orange_catalog_safe_exec($pdo, 'ALTER TABLE purchases ADD COLUMN invoice_discount_amount DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    if (orange_table_exists($pdo, 'purchases') && !orange_table_has_column($pdo, 'purchases', 'subtotal')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE purchases ADD COLUMN subtotal DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    if (orange_table_exists($pdo, 'purchase_return_items') && !orange_table_has_column($pdo, 'purchase_return_items', 'discount_raw')) {
        orange_catalog_safe_exec($pdo, "ALTER TABLE purchase_return_items ADD COLUMN discount_raw VARCHAR(32) NOT NULL DEFAULT ''");
        orange_catalog_safe_exec($pdo, 'ALTER TABLE purchase_return_items ADD COLUMN discount_amount DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    if (orange_table_exists($pdo, 'purchase_returns') && !orange_table_has_column($pdo, 'purchase_returns', 'invoice_discount_raw')) {
        orange_catalog_safe_exec($pdo, "ALTER TABLE purchase_returns ADD COLUMN invoice_discount_raw VARCHAR(32) NOT NULL DEFAULT ''");
        orange_catalog_safe_exec($pdo, 'ALTER TABLE purchase_returns ADD COLUMN invoice_discount_amount DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    if (orange_table_exists($pdo, 'purchase_returns') && !orange_table_has_column($pdo, 'purchase_returns', 'subtotal')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE purchase_returns ADD COLUMN subtotal DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    if (orange_table_exists($pdo, 'purchases') && !orange_table_has_column($pdo, 'purchases', 'supplier_invoice_number')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE purchases ADD COLUMN supplier_invoice_number VARCHAR(64) NULL DEFAULT NULL AFTER supplier_id"
        );
    }

    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'vat_number')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE company_settings ADD COLUMN vat_number VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }
    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'vat_rate')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE company_settings ADD COLUMN vat_rate DECIMAL(6,3) NOT NULL DEFAULT 0'
        );
    }
    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'invoice_footer_ar')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE company_settings ADD COLUMN invoice_footer_ar TEXT NULL');
    }
    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'invoice_footer_en')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE company_settings ADD COLUMN invoice_footer_en TEXT NULL');
    }

    if (!orange_table_exists($pdo, 'storefront_home_hero')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS storefront_home_hero (
                id INT NOT NULL PRIMARY KEY,
                line_1_ar VARCHAR(500) NOT NULL DEFAULT \'\',
                line_1_en VARCHAR(500) NOT NULL DEFAULT \'\',
                line_1_fil VARCHAR(500) NOT NULL DEFAULT \'\',
                line_1_hi VARCHAR(500) NOT NULL DEFAULT \'\',
                line_2_ar VARCHAR(500) NOT NULL DEFAULT \'\',
                line_2_en VARCHAR(500) NOT NULL DEFAULT \'\',
                line_2_fil VARCHAR(500) NOT NULL DEFAULT \'\',
                line_2_hi VARCHAR(500) NOT NULL DEFAULT \'\',
                line_3_ar VARCHAR(500) NOT NULL DEFAULT \'\',
                line_3_en VARCHAR(500) NOT NULL DEFAULT \'\',
                line_3_fil VARCHAR(500) NOT NULL DEFAULT \'\',
                line_3_hi VARCHAR(500) NOT NULL DEFAULT \'\',
                header_tagline_ar VARCHAR(500) NOT NULL DEFAULT \'\',
                header_tagline_en VARCHAR(500) NOT NULL DEFAULT \'\',
                header_tagline_fil VARCHAR(500) NOT NULL DEFAULT \'\',
                header_tagline_hi VARCHAR(500) NOT NULL DEFAULT \'\',
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_catalog_safe_exec($pdo, 'INSERT INTO storefront_home_hero (id) VALUES (1)');
    }
    if (orange_table_exists($pdo, 'storefront_home_hero')) {
        $htMigrations = [
            'header_tagline_ar' => "ADD COLUMN header_tagline_ar VARCHAR(500) NOT NULL DEFAULT ''",
            'header_tagline_en' => "ADD COLUMN header_tagline_en VARCHAR(500) NOT NULL DEFAULT ''",
            'header_tagline_fil' => "ADD COLUMN header_tagline_fil VARCHAR(500) NOT NULL DEFAULT ''",
            'header_tagline_hi' => "ADD COLUMN header_tagline_hi VARCHAR(500) NOT NULL DEFAULT ''",
        ];
        foreach ($htMigrations as $col => $fragment) {
            if (!orange_table_has_column($pdo, 'storefront_home_hero', $col)) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE storefront_home_hero ' . $fragment);
            }
        }
    }

    if (!orange_table_exists($pdo, 'storefront_copy_lines')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS storefront_copy_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                scope VARCHAR(32) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                text_ar VARCHAR(500) NOT NULL DEFAULT \'\',
                text_en VARCHAR(500) NOT NULL DEFAULT \'\',
                text_fil VARCHAR(500) NOT NULL DEFAULT \'\',
                text_hi VARCHAR(500) NOT NULL DEFAULT \'\',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_storefront_copy_scope (scope, is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    /* legacy copy_lines migrate: بعد v52 (country_id) — انظر orange_catalog_migrate_legacy_storefront_copy_lines */

    orange_catalog_migrate_countries_foundation_v40($pdo);
    orange_catalog_migrate_governorates_v41($pdo);
    orange_catalog_migrate_country_market_codes_v42($pdo);
    orange_catalog_migrate_country_sort_renumber_v43($pdo);
    orange_catalog_migrate_country_warehouses_v44($pdo);
    orange_catalog_migrate_country_scope_v45($pdo);
    orange_catalog_migrate_country_accounts_v46($pdo);
    orange_catalog_migrate_country_gl_v47($pdo);
    orange_catalog_migrate_country_cart_promotions_v48($pdo);
    orange_catalog_migrate_country_gl_accounts_v49($pdo);
    orange_catalog_migrate_country_repair_v50($pdo);
    orange_catalog_migrate_department_countries_v51($pdo);
    orange_catalog_migrate_country_admin_settings_v52($pdo);
    orange_catalog_migrate_gl_journal_type_rules_country_v53($pdo);
    orange_catalog_migrate_gl_settings_journal_type_remap_v54($pdo);
    orange_catalog_migrate_channel_country_default_v55($pdo);
    orange_catalog_migrate_document_currency_v56($pdo);
    orange_catalog_migrate_supplier_country_currency_v57($pdo);
    orange_catalog_migrate_journal_types_non_default_purge_v58($pdo);
    orange_catalog_migrate_journal_types_country_scope_repair_v59($pdo);
    orange_catalog_migrate_journal_types_strip_non_kw_v60($pdo);
    orange_catalog_migrate_journal_types_strip_non_kw_v61($pdo);
    orange_catalog_migrate_legacy_storefront_copy_lines($pdo);
    require_once __DIR__ . '/catalog_multicountry_stock_schema.php';
    orange_catalog_migrate_multicountry_stock_v69($pdo);
    require_once __DIR__ . '/catalog_storefront_payment_schema.php';
    orange_catalog_migrate_storefront_payment_v70($pdo);
    require_once __DIR__ . '/country_scope_repair.php';
    orange_catalog_migrate_country_scope_repair_v71($pdo);
    orange_catalog_migrate_country_scope_repair_v72($pdo);
    orange_catalog_migrate_country_scope_repair_v73($pdo);
    orange_catalog_migrate_country_scope_repair_v74($pdo);
    orange_catalog_migrate_country_scope_repair_v75($pdo);
    orange_catalog_migrate_country_scope_repair_v76($pdo);
    orange_catalog_migrate_country_scope_repair_v77($pdo);
    orange_catalog_migrate_sales_returns_analytics_v78($pdo);
    orange_catalog_migrate_cart_promo_schedule_v79($pdo);
    orange_catalog_migrate_product_offers_schedule_v80($pdo);
    orange_catalog_migrate_product_offers_sort_order_v81($pdo);
    orange_catalog_migrate_cart_promo_pause_log_v82($pdo);
    orange_catalog_migrate_cart_promo_stock_check_v83($pdo);
    orange_catalog_migrate_document_business_date_v84($pdo);
    orange_catalog_migrate_inventory_cost_layers_v89($pdo);
    orange_catalog_migrate_delivery_policy_checkout_otp_v90($pdo);
    orange_catalog_migrate_delivery_promotions_invoice_lines_v91($pdo);
    orange_catalog_migrate_delivery_fee_apply_mode_v92($pdo);
    orange_catalog_migrate_delivery_fee_pending_v93($pdo);
    orange_catalog_migrate_promotions_always_on_v94($pdo);
    orange_catalog_migrate_offer_gl_link_v95($pdo);
    orange_catalog_migrate_loyalty_journal_rules_seed_v96($pdo);
    orange_catalog_migrate_stock_adjustment_gain_loss_v97($pdo);
    orange_catalog_migrate_delivery_agents_sort_renumber_v98($pdo);
    orange_catalog_migrate_advisory_sizing_clean_wipe_v99($pdo);
    orange_catalog_migrate_db_id_renumber_phases($pdo);
    orange_admin_migrate_permissions_to_pages($pdo);
    orange_admin_purge_obsolete_page_permissions($pdo);
    orange_admin_seed_company_sales_invoice_page_permissions($pdo);
    orange_admin_seed_online_sales_invoice_page_permissions($pdo);

    if (!orange_table_exists($pdo, 'delivery_areas')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS delivery_areas (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
                name_en VARCHAR(191) NOT NULL DEFAULT \'\',
                delivery_fee DECIMAL(18,4) NOT NULL DEFAULT 0,
                delivery_fee_pending TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'delivery_areas') && !orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE delivery_areas ADD COLUMN delivery_fee DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER name_en'
        );
        orange_schema_invalidate_column_check('delivery_areas', 'delivery_fee');
    }
    if (orange_table_exists($pdo, 'delivery_areas') && !orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee_pending')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE delivery_areas ADD COLUMN delivery_fee_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_fee'
        );
        orange_schema_invalidate_column_check('delivery_areas', 'delivery_fee_pending');
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'delivery_area_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN delivery_area_id INT UNSIGNED NULL DEFAULT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_orders_delivery_area_id ON orders (delivery_area_id)');
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'delivery_fee')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER total'
        );
        orange_schema_invalidate_column_check('orders', 'delivery_fee');
    }

    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'customer_delivery_area_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN customer_delivery_area_id INT UNSIGNED NULL DEFAULT NULL'
        );
    }

    if (!orange_table_exists($pdo, 'cart_promotions')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS cart_promotions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                min_subtotal DECIMAL(18,4) NOT NULL DEFAULT 0,
                discount_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
                requires_registered_account TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_always_on TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cart_promotions_active_min (is_active, min_subtotal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'cart_promotion_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN cart_promotion_id INT UNSIGNED NULL DEFAULT NULL');
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'cart_promotion_discount')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN cart_promotion_discount DECIMAL(18,4) NOT NULL DEFAULT 0'
        );
    }

    if (!orange_table_exists($pdo, 'cart_gift_promotions')) {
        orange_catalog_safe_exec(
            $pdo,
            "CREATE TABLE IF NOT EXISTS cart_gift_promotions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                min_subtotal DECIMAL(18,4) NOT NULL DEFAULT 0,
                requires_registered_account TINYINT(1) NOT NULL DEFAULT 0,
                gift_kind VARCHAR(16) NOT NULL DEFAULT 'choice',
                fixed_variant_id INT UNSIGNED NULL DEFAULT NULL,
                pool_variant_ids TEXT NULL,
                gift_unit_charge_kind VARCHAR(24) NOT NULL DEFAULT 'free',
                gift_unit_charge_value DECIMAL(18,4) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_always_on TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cart_gift_promo_active_min (is_active, min_subtotal)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (orange_table_exists($pdo, 'cart_gift_promotions') && !orange_table_has_column($pdo, 'cart_gift_promotions', 'gift_unit_charge_kind')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE cart_gift_promotions ADD COLUMN gift_unit_charge_kind VARCHAR(24) NOT NULL DEFAULT 'free'"
        );
    }
    if (orange_table_exists($pdo, 'cart_gift_promotions') && !orange_table_has_column($pdo, 'cart_gift_promotions', 'gift_unit_charge_value')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE cart_gift_promotions ADD COLUMN gift_unit_charge_value DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'cart_gift_promotion_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN cart_gift_promotion_id INT UNSIGNED NULL DEFAULT NULL');
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'cart_gift_variant_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN cart_gift_variant_id INT UNSIGNED NULL DEFAULT NULL');
    }

    if (!orange_table_exists($pdo, 'cart_bogo_promotions')) {
        orange_catalog_safe_exec(
            $pdo,
            "CREATE TABLE IF NOT EXISTS cart_bogo_promotions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                bogo_kind VARCHAR(24) NOT NULL DEFAULT 'same_variant',
                category_id INT UNSIGNED NULL DEFAULT NULL,
                min_buy_qty INT UNSIGNED NOT NULL DEFAULT 2,
                buy_components_json TEXT NULL,
                requires_registered_account TINYINT(1) NOT NULL DEFAULT 0,
                gift_kind VARCHAR(16) NOT NULL DEFAULT 'choice',
                fixed_variant_id INT UNSIGNED NULL DEFAULT NULL,
                pool_variant_ids TEXT NULL,
                gift_unit_charge_kind VARCHAR(24) NOT NULL DEFAULT 'free',
                gift_unit_charge_value DECIMAL(18,4) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_always_on TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cart_bogo_active_sort (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (orange_table_exists($pdo, 'cart_bogo_promotions') && !orange_table_has_column($pdo, 'cart_bogo_promotions', 'gift_unit_charge_kind')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE cart_bogo_promotions ADD COLUMN gift_unit_charge_kind VARCHAR(24) NOT NULL DEFAULT 'free'"
        );
    }
    if (orange_table_exists($pdo, 'cart_bogo_promotions') && !orange_table_has_column($pdo, 'cart_bogo_promotions', 'gift_unit_charge_value')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE cart_bogo_promotions ADD COLUMN gift_unit_charge_value DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    if (orange_table_exists($pdo, 'cart_bogo_promotions') && !orange_table_has_column($pdo, 'cart_bogo_promotions', 'buy_components_json')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE cart_bogo_promotions ADD COLUMN buy_components_json TEXT NULL');
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'cart_bogo_promotion_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN cart_bogo_promotion_id INT UNSIGNED NULL DEFAULT NULL');
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'cart_bogo_gift_variant_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN cart_bogo_gift_variant_id INT UNSIGNED NULL DEFAULT NULL');
    }

    if (!orange_table_exists($pdo, 'cart_combo_promotions')) {
        orange_catalog_safe_exec(
            $pdo,
            "CREATE TABLE IF NOT EXISTS cart_combo_promotions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title_ar VARCHAR(191) NOT NULL DEFAULT '',
                title_en VARCHAR(191) NOT NULL DEFAULT '',
                components_json TEXT NOT NULL,
                combo_price DECIMAL(18,4) NOT NULL DEFAULT 0,
                requires_registered_account TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_always_on TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cart_combo_active_sort (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    foreach ([
        'cart_promotions',
        'cart_gift_promotions',
        'cart_bogo_promotions',
        'cart_combo_promotions',
        'offers',
        'delivery_fee_promotions',
    ] as $promoTableAlwaysOn) {
        if (orange_table_exists($pdo, $promoTableAlwaysOn) && !orange_table_has_column($pdo, $promoTableAlwaysOn, 'is_always_on')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE ' . $promoTableAlwaysOn . ' ADD COLUMN is_always_on TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
            );
            orange_schema_invalidate_column_check($promoTableAlwaysOn, 'is_always_on');
        }
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'cart_combo_promotion_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN cart_combo_promotion_id INT UNSIGNED NULL DEFAULT NULL');
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'cart_combo_discount')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN cart_combo_discount DECIMAL(18,4) NOT NULL DEFAULT 0'
        );
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'invoice_number')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE orders ADD COLUMN invoice_number VARCHAR(32) NULL DEFAULT NULL"
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE UNIQUE INDEX uq_orders_invoice_number ON orders (invoice_number)'
        );
    }

    if (orange_table_exists($pdo, 'order_items') && !orange_table_has_column($pdo, 'order_items', 'line_discount')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE order_items ADD COLUMN line_discount DECIMAL(18,4) NOT NULL DEFAULT 0'
        );
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'amount_paid')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN amount_paid DECIMAL(18,4) NOT NULL DEFAULT 0'
        );
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'completed_at')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN completed_at DATETIME NULL DEFAULT NULL'
        );
    }

    if (!orange_table_exists($pdo, 'delivery_agents')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS delivery_agents (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                country_id INT UNSIGNED NOT NULL,
                name_ar VARCHAR(191) NOT NULL,
                name_en VARCHAR(191) NULL DEFAULT NULL,
                phone VARCHAR(32) NULL DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'active\',
                sort_order INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_delivery_agents_country (country_id),
                KEY idx_delivery_agents_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'delivery_agent_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN delivery_agent_id INT UNSIGNED NULL DEFAULT NULL'
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_orders_delivery_agent_id ON orders (delivery_agent_id)'
        );
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'promo_admin_override')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN promo_admin_override TEXT NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'order_items') && !orange_table_has_column($pdo, 'order_items', 'combo_group_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE order_items ADD COLUMN combo_group_id INT UNSIGNED NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'order_items')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE order_items MODIFY COLUMN product_id INT NULL');
    }

    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'opening_stock_locked')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE company_settings ADD COLUMN opening_stock_locked TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    if (orange_table_exists($pdo, 'journal_vouchers') && !orange_table_has_column($pdo, 'journal_vouchers', 'is_void')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE journal_vouchers ADD COLUMN is_void TINYINT(1) NOT NULL DEFAULT 0'
        );
    }
    if (orange_table_exists($pdo, 'journal_vouchers') && !orange_table_has_column($pdo, 'journal_vouchers', 'voided_at')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE journal_vouchers ADD COLUMN voided_at DATETIME NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'journal_vouchers') && !orange_table_has_column($pdo, 'journal_vouchers', 'yec_locked')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE journal_vouchers ADD COLUMN yec_locked TINYINT(1) NOT NULL DEFAULT 0'
        );
    }
    if (orange_table_exists($pdo, 'journal_lines') && !orange_table_has_column($pdo, 'journal_lines', 'yec_phase')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE journal_lines ADD COLUMN yec_phase VARCHAR(8) NULL DEFAULT NULL AFTER memo"
        );
    }

    if (!orange_table_exists($pdo, 'expenses')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL DEFAULT \'\',
                amount DECIMAL(18,4) NOT NULL DEFAULT 0,
                expense_account_id INT NULL,
                notes VARCHAR(512) NOT NULL DEFAULT \'\',
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_expenses_expense_account (expense_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'expenses') && !orange_table_has_column($pdo, 'expenses', 'expense_account_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE expenses ADD COLUMN expense_account_id INT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_expenses_expense_account ON expenses (expense_account_id)');
    }
    if (orange_table_exists($pdo, 'expenses') && !orange_table_has_column($pdo, 'expenses', 'notes')) {
        orange_catalog_safe_exec($pdo, "ALTER TABLE expenses ADD COLUMN notes VARCHAR(512) NOT NULL DEFAULT ''");
    }
    if (orange_table_exists($pdo, 'expenses') && !orange_table_has_column($pdo, 'expenses', 'updated_at')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE expenses ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'
        );
    }

    if (!orange_table_exists($pdo, 'orange_company_documents')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_company_documents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                title_ar VARCHAR(255) NOT NULL DEFAULT \'\',
                doc_type VARCHAR(48) NOT NULL DEFAULT \'other\',
                reference_number VARCHAR(128) NOT NULL DEFAULT \'\',
                doc_date DATE NULL DEFAULT NULL,
                entity_table VARCHAR(64) NOT NULL DEFAULT \'\',
                entity_id VARCHAR(64) NOT NULL DEFAULT \'\',
                notes TEXT NULL,
                storage_path VARCHAR(512) NOT NULL,
                original_filename VARCHAR(255) NOT NULL DEFAULT \'\',
                mime_type VARCHAR(128) NOT NULL DEFAULT \'\',
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_by_admin_id INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_ocd_type (doc_type),
                KEY idx_ocd_entity (entity_table, entity_id),
                KEY idx_ocd_created (created_at),
                KEY idx_ocd_ref (reference_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'orange_admin_audit_log')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_admin_audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                admin_id INT NULL,
                action VARCHAR(80) NOT NULL,
                message TEXT NOT NULL,
                entity_table VARCHAR(80) NOT NULL DEFAULT \'\',
                entity_id VARCHAR(64) NOT NULL DEFAULT \'\',
                PRIMARY KEY (id),
                KEY idx_orange_audit_created (created_at),
                KEY idx_orange_audit_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE storefront_accounts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL,
                registered_channel_slug VARCHAR(32) NULL DEFAULT NULL,
                email_verified_at DATETIME NULL DEFAULT NULL,
                verify_token_hash CHAR(64) NOT NULL DEFAULT \'\',
                verify_token_expires_at DATETIME NULL DEFAULT NULL,
                verify_email_sent_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_storefront_accounts_email (email),
                KEY idx_storefront_accounts_verified (email_verified_at),
                KEY idx_storefront_accounts_channel (registered_channel_slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN registered_channel_slug VARCHAR(32) NULL DEFAULT NULL'
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_storefront_accounts_channel ON storefront_accounts (registered_channel_slug)'
        );
        try {
            $defCh = $pdo->query('SELECT slug FROM channels WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
            if ($defCh !== false && $defCh !== null && (string) $defCh !== '') {
                $u = $pdo->prepare(
                    'UPDATE storefront_accounts SET registered_channel_slug = ? WHERE registered_channel_slug IS NULL AND email_verified_at IS NOT NULL'
                );
                $u->execute([(string) $defCh]);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] storefront_accounts default channel slug: ' . $e->getMessage());
            }
        }
    }

    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'customer_name')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN customer_name VARCHAR(255) NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN customer_phone VARCHAR(64) NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_country_dial')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN customer_phone_country_dial VARCHAR(8) NULL DEFAULT NULL AFTER customer_phone'
        );
    }
    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'customer_phone_national')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN customer_phone_national VARCHAR(32) NULL DEFAULT NULL AFTER customer_phone_country_dial'
        );
    }
    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'customer_area')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN customer_area VARCHAR(255) NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'customer_address')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN customer_address TEXT NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'customer_notes')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN customer_notes TEXT NULL DEFAULT NULL'
        );
    }
    if (orange_table_exists($pdo, 'storefront_accounts') && !orange_table_has_column($pdo, 'storefront_accounts', 'customer_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_accounts ADD COLUMN customer_id INT NULL DEFAULT NULL'
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_storefront_accounts_customer ON storefront_accounts (customer_id)'
        );
        // س15: backfill لمرة واحدة — كل حساب مفعَّل (email_verified_at IS NOT NULL) ينشئ/يربط صفه في customers.
        try {
            require_once __DIR__ . '/party_subledger.php';
            $bf = $pdo->query(
                'SELECT id FROM storefront_accounts
                 WHERE email_verified_at IS NOT NULL AND customer_id IS NULL
                   AND customer_phone IS NOT NULL AND customer_phone <> \'\''
            );
            if ($bf) {
                while ($accIdToSync = $bf->fetchColumn()) {
                    try {
                        orange_sync_storefront_account_to_customer($pdo, (int) $accIdToSync);
                    } catch (Throwable $eInner) {
                        if (function_exists('error_log')) {
                            error_log('[orange] backfill sync acc #' . (int) $accIdToSync . ': ' . $eInner->getMessage());
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] backfill storefront_accounts→customers: ' . $e->getMessage());
            }
        }
    }

    if (!orange_table_exists($pdo, 'storefront_phone_merge_requests')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE storefront_phone_merge_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                storefront_account_id INT UNSIGNED NOT NULL,
                phone_normalized VARCHAR(64) NOT NULL,
                proposed_email VARCHAR(255) NOT NULL,
                proposed_channel_slug VARCHAR(32) NULL DEFAULT NULL,
                proposed_name VARCHAR(255) NULL DEFAULT NULL,
                proposed_delivery_area_id INT UNSIGNED NULL DEFAULT NULL,
                proposed_area VARCHAR(255) NULL DEFAULT NULL,
                proposed_address TEXT NULL DEFAULT NULL,
                proposed_notes TEXT NULL DEFAULT NULL,
                proposed_phone_country_dial VARCHAR(8) NULL DEFAULT NULL,
                proposed_phone_national VARCHAR(32) NULL DEFAULT NULL,
                merge_token_hash CHAR(64) NOT NULL,
                wa_confirmed_at DATETIME NULL DEFAULT NULL,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_spmr_account (storefront_account_id),
                KEY idx_spmr_phone (phone_normalized),
                KEY idx_spmr_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    require_once __DIR__ . '/acc10_schema.php';
    orange_catalog_ensure_acc10_schema($pdo);

    require_once __DIR__ . '/edit_lock_schema.php';
    orange_catalog_ensure_edit_lock_schema($pdo);

    require_once __DIR__ . '/invoice_ancillary_lines_schema.php';
    orange_catalog_ensure_invoice_ancillary_lines_schema($pdo);

    require_once __DIR__ . '/schema_migrations.php';
    orange_schema_run_pending_migrations($pdo);

    require_once __DIR__ . '/document_sequences.php';
    orange_orders_migrate_legacy_invoice_numbers_v1($pdo);

    orange_catalog_schema_checkpoint_save($pdo, ORANGE_CATALOG_SCHEMA_PHP_REVISION);
    orange_schema_meta_save($pdo, ORANGE_CATALOG_SCHEMA_PHP_REVISION);

    $done = true;
}

/**
 * ترحيل مرقّم اختياري: scripts/migrations/001.sql … NNN.sql ثم النواة الكاملة (انظر ORANGE_STRICT_NUMBERED_SQL_MIGRATIONS في config / .env.php).
 *
 * @param int|null $_currentDbVersion إصدار orange_schema_meta الحالي (صف id=1)؛ null يعيد القراءة من القاعدة.
 */
function orange_run_migrations(PDO $pdo, ?int $_currentDbVersion = null): void
{
    require_once __DIR__ . '/schema_migrations.php';
    orange_schema_run_numbered_sql_chain($pdo, $_currentDbVersion);
    orange_catalog_ensure_schema_core($pdo);
}

/**
 * شريحة المسار السريع (بدون النواة الكاملة — آلاف أسطر DDL).
 */
/**
 * جدول التوكنات العام لمستندات الفاتورة/المردود (QR — س27 استثناء معتمد 2026-06-12).
 * يُستدعى من النواة الكاملة ومن المسار السريع حتى يُنشأ حتى على القواعد القائمة (rev متطابق).
 */
function orange_catalog_ensure_document_public_tokens_table(PDO $pdo): void
{
    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS document_public_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token CHAR(40) NOT NULL,
            doc_kind VARCHAR(32) NOT NULL,
            doc_id INT NOT NULL,
            country_id INT NULL,
            revoked TINYINT(1) NOT NULL DEFAULT 0,
            expires_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_doc_public_token (token),
            UNIQUE KEY uq_doc_public_doc (doc_kind, doc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function orange_catalog_ensure_schema_fast_path_slice(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    orange_schema_run_pending_migrations($pdo);
    orange_catalog_migrate_delivery_promotions_invoice_lines_v91($pdo);
    orange_catalog_migrate_delivery_fee_apply_mode_v92($pdo);
    orange_catalog_migrate_delivery_fee_pending_v93($pdo);
    orange_catalog_migrate_promotions_always_on_v94($pdo);
    orange_catalog_migrate_offer_gl_link_v95($pdo);
    orange_catalog_migrate_loyalty_journal_rules_seed_v96($pdo);
    orange_catalog_migrate_stock_adjustment_gain_loss_v97($pdo);
    orange_catalog_migrate_delivery_agents_sort_renumber_v98($pdo);
    orange_catalog_migrate_advisory_sizing_clean_wipe_v99($pdo);
    foreach ([
        'cart_promotions',
        'cart_gift_promotions',
        'cart_bogo_promotions',
        'cart_combo_promotions',
        'offers',
        'delivery_fee_promotions',
    ] as $promoTableAlwaysOn) {
        if (orange_table_exists($pdo, $promoTableAlwaysOn) && !orange_table_has_column($pdo, $promoTableAlwaysOn, 'is_always_on')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE ' . $promoTableAlwaysOn . ' ADD COLUMN is_always_on TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
            );
            orange_schema_invalidate_column_check($promoTableAlwaysOn, 'is_always_on');
        }
    }
    orange_catalog_ensure_document_public_tokens_table($pdo);
    if (orange_table_exists($pdo, 'delivery_areas') && !orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE delivery_areas ADD COLUMN delivery_fee DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER name_en'
        );
        orange_schema_invalidate_column_check('delivery_areas', 'delivery_fee');
    }
    if (orange_table_exists($pdo, 'delivery_areas') && !orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee_pending')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE delivery_areas ADD COLUMN delivery_fee_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_fee'
        );
        orange_schema_invalidate_column_check('delivery_areas', 'delivery_fee_pending');
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'delivery_fee')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER total'
        );
        orange_schema_invalidate_column_check('orders', 'delivery_fee');
    }
    if (orange_table_exists($pdo, 'advisory_sizing_library_bundles') && !orange_table_has_column($pdo, 'advisory_sizing_library_bundles', 'department_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE advisory_sizing_library_bundles ADD COLUMN department_id INT NULL DEFAULT NULL AFTER id');
    }
    if (orange_table_exists($pdo, 'advisory_sizing_library_bundles') && !orange_table_has_column($pdo, 'advisory_sizing_library_bundles', 'size_scheme_template_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE advisory_sizing_library_bundles ADD COLUMN size_scheme_template_id INT NULL DEFAULT NULL AFTER department_id');
    }
    orange_catalog_ensure_suppliers_schema($pdo);
    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'vat_rate')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE company_settings ADD COLUMN vat_rate DECIMAL(6,3) NOT NULL DEFAULT 0');
        orange_schema_invalidate_column_check('company_settings', 'vat_rate');
    }
    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'low_stock_threshold')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE company_settings ADD COLUMN low_stock_threshold INT NOT NULL DEFAULT 3');
        orange_schema_invalidate_column_check('company_settings', 'low_stock_threshold');
    }
    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'customer_low_stock_threshold')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE company_settings ADD COLUMN customer_low_stock_threshold INT NOT NULL DEFAULT 5');
        orange_schema_invalidate_column_check('company_settings', 'customer_low_stock_threshold');
    }
    orange_catalog_ensure_journal_types_country_scope($pdo);
    orange_catalog_seed_default_accounts_if_empty($pdo);
    orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);
    require_once __DIR__ . '/journal_types.php';
    try {
        $jtDefaultCid = orange_countries_default_id($pdo);
        if ($jtDefaultCid > 0 && orange_journal_types_should_auto_seed($pdo, $jtDefaultCid)) {
            orange_journal_types_merge_canonical_defaults($pdo, $jtDefaultCid);
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] canonical journal_types merge (fast path): ' . $e->getMessage());
        }
    }
}

/**
 * عند تأخر إصدار القاعدة عن الكود: على الويب ترحيل خفيف فقط (يمنع HTTP 500).
 * النواة الكاملة: php scripts/run_migrations.php على السيرفر (CLI).
 */
function orange_catalog_schema_web_version_catchup(PDO $pdo, int $dbVersion): void
{
    if (PHP_SAPI === 'cli') {
        orange_run_migrations($pdo, $dbVersion);

        return;
    }

    @ini_set('max_execution_time', '0');
    @set_time_limit(0);

    require_once __DIR__ . '/schema_migrations.php';
    orange_schema_run_numbered_sql_chain($pdo, $dbVersion);
    orange_catalog_ensure_schema_fast_path_slice($pdo);

    $rev = ORANGE_SCHEMA_CODE_VERSION;
    orange_schema_meta_save($pdo, $rev);
    orange_catalog_schema_checkpoint_save($pdo, $rev);

    if (function_exists('error_log')) {
        error_log(
            '[orange] Web schema catch-up to rev ' . (string) $rev
            . ' (lightweight). CLI: php scripts/run_migrations.php and php scripts/run_db_id_renumber_phases.php'
        );
    }
}

/**
 * بوابة نشر الويب: قراءة إصدار القاعدة، سلسلة ###.sql عند الحاجة، ثم النواة؛ اختياري APCu ووضع متدهور عند الفشل (إعدادات).
 */
function orange_schema_check_and_bootstrap(PDO $pdo): void
{
    orange_catalog_ensure_country_id_columns_once($pdo);

    static $gateOk = false;
    if ($gateOk) {
        return;
    }
    static $bootstrapFailedDegraded = false;
    if ($bootstrapFailedDegraded) {
        return;
    }

    $apcuTtl = (int) (getenv('ORANGE_SCHEMA_APCU_GATE_SECONDS') ?: '0');
    $apcuKey = 'orange_schema_gate_' . (string) ORANGE_SCHEMA_CODE_VERSION;
    if ($apcuTtl > 0 && function_exists('apcu_fetch') && apcu_fetch($apcuKey)) {
        $gateOk = true;

        return;
    }

    $okFlagPath = trim((string) (getenv('ORANGE_SCHEMA_OK_FLAG_PATH') ?: ''));
    if ($okFlagPath !== '' && is_readable($okFlagPath)) {
        $fc = @file_get_contents($okFlagPath);
        if ($fc !== false) {
            $parts = preg_split("/\R/", $fc, 2);
            $line = trim((string) ($parts[0] ?? ''));
            if ($line === (string) ORANGE_SCHEMA_CODE_VERSION) {
                $gateOk = true;

                return;
            }
        }
    }

    $catch = defined('ORANGE_SCHEMA_CATCH_BOOTSTRAP_FAILURE') && ORANGE_SCHEMA_CATCH_BOOTSTRAP_FAILURE;

    try {
        orange_schema_meta_ensure_table($pdo);
        $st = $pdo->query('SELECT version FROM orange_schema_meta WHERE id = 1 LIMIT 1');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        $dbVersion = $row ? (int) ($row['version'] ?? 0) : 0;

        if ($dbVersion < ORANGE_SCHEMA_CODE_VERSION) {
            orange_catalog_schema_web_version_catchup($pdo, $dbVersion);
        } else {
            require_once __DIR__ . '/schema_migrations.php';
            orange_schema_run_pending_migrations($pdo);
            if (PHP_SAPI === 'cli') {
                orange_catalog_ensure_schema_core($pdo);
            } else {
                orange_catalog_ensure_schema_fast_path_slice($pdo);
            }
        }

        if (PHP_SAPI === 'cli') {
            require_once __DIR__ . '/catalog_taxonomy_migrate.php';
            orange_catalog_post_schema_legacy_unified($pdo);
            require_once __DIR__ . '/product_channels.php';
            orange_product_channels_ensure_missing_links($pdo);
            orange_catalog_ensure_products_product_type_id_not_null($pdo);
            orange_catalog_ensure_products_drop_legacy_classification_columns($pdo);
            orange_catalog_ensure_products_product_type_id_not_null($pdo);
            require_once __DIR__ . '/catalog_legacy_tables_drop_phase54.php';
            orange_catalog_ensure_legacy_taxonomy_tables_dropped_phase54($pdo);
            require_once __DIR__ . '/multicountry_stock_gap.php';
            orange_multicountry_ensure_stock_scoped_phase1($pdo);
            orange_multicountry_ensure_operational_phase2($pdo);
        }

        if ($apcuTtl > 0 && function_exists('apcu_store')) {
            @apcu_store($apcuKey, 1, $apcuTtl);
        }
        $gateOk = true;
    } catch (Throwable $e) {
        if ($catch) {
            if (function_exists('error_log')) {
                error_log('[orange] schema bootstrap: ' . $e->getMessage());
            }
            if (!defined('ORANGE_SCHEMA_DEGRADED')) {
                define('ORANGE_SCHEMA_DEGRADED', true);
            }
            $bootstrapFailedDegraded = true;

            return;
        }
        throw $e;
    }
}

/** @see orange_schema_check_and_bootstrap — نقطة الدخول العامة لكل الاستدعاءات القائمة. */
function orange_catalog_ensure_schema(PDO $pdo): void
{
    if (PHP_SAPI === 'cli') {
        try {
            orange_catalog_migrate_db_id_renumber_phases($pdo);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] db_id_renumber_phases: ' . $e->getMessage());
            }
        }
    }
    orange_schema_check_and_bootstrap($pdo);
    if (PHP_SAPI === 'cli' || orange_catalog_is_admin_http_request()) {
        orange_catalog_runtime_light_hooks($pdo);
    }
}

/**
 * مهام idempotent خفيفة بعد بوابة المخطط (تُنفَّذ حتى مع APCu/ok-flag — probe سريع ثم خروج).
 */
function orange_catalog_runtime_light_hooks(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    try {
        require_once __DIR__ . '/catalog_multicountry_runtime.php';
        orange_catalog_ensure_multicountry_phase4($pdo);
        require_once __DIR__ . '/product_channels.php';
        orange_product_channels_ensure_missing_links($pdo);
        require_once __DIR__ . '/catalog_kw_product_types_seed.php';
        orange_catalog_ensure_kw_product_types($pdo);
        require_once __DIR__ . '/catalog_kw_products_phase3.php';
        orange_catalog_ensure_kw_products_phase3($pdo);
        require_once __DIR__ . '/catalog_legacy_closure_phase5.php';
        orange_catalog_ensure_legacy_closure_phase5($pdo);
        require_once __DIR__ . '/catalog_polish_phase6.php';
        orange_catalog_ensure_polish_phase6($pdo);
        require_once __DIR__ . '/catalog_legacy_tables_drop_phase54.php';
        orange_catalog_ensure_legacy_taxonomy_tables_dropped_phase54($pdo);
        require_once __DIR__ . '/multicountry_stock_gap.php';
        orange_multicountry_ensure_stock_scoped_phase1($pdo);
        orange_multicountry_ensure_operational_phase2($pdo);
        require_once __DIR__ . '/payments/payment_schema.php';
        orange_payments_ensure_schema($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_runtime_light_hooks: ' . $e->getMessage());
        }
    }
}

/**
 * عدد صفوف نطاق معيّن في storefront_copy_lines.
 */
function orange_catalog_count_storefront_copy_scope(PDO $pdo, string $scope, ?int $countryId = null): int
{
    try {
        if (orange_table_has_column($pdo, 'storefront_copy_lines', 'country_id') && $countryId !== null && $countryId > 0) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM storefront_copy_lines WHERE country_id = ? AND scope = ?');
            $st->execute([$countryId, $scope]);
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM storefront_copy_lines WHERE scope = ?');
            $st->execute([$scope]);
        }

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return PHP_INT_MAX;
    }
}

/**
 * قيم أولية لـ storefront_copy_lines (مصدر واحد للتثبيت؛ العرض من الجدول فقط).
 *
 * @return array{home_hero: list<array{ar:string,en:string,fil:string,hi:string}>, header_tagline: array{ar:string,en:string,fil:string,hi:string}}
 */
function orange_catalog_builtin_storefront_copy_defaults(): array
{
    return [
        'home_hero' => [
            [
                'ar' => 'كل ما تبحث عنه ... في مكان واحد',
                'en' => "Everything you're looking for ... in one place",
                'fil' => 'Lahat ng iyong hinahanap ... sa iisang lugar',
                'hi' => 'वह सब कुछ जो आप ढूंढ रहे हैं ... एक ही जगह पर।',
            ],
            [
                'ar' => 'تسوق براحة بال • دفع عند الاستلام • إرجاع سهل',
                'en' => 'Shop with Peace of Mind • COD • Easy Returns',
                'fil' => 'Kampanteng Pagbili • COD • Madaling Return',
                'hi' => 'निश्चिंत होकर खरीदारी • कैश ऑन डिलीवरी • आसान रिटर्न',
            ],
            [
                'ar' => 'وفر أكثر • أقل سعر • أسرع توصيل',
                'en' => 'Save More • Best Price • Fast Delivery',
                'fil' => 'Makatipid Pa • Murang Presyo • Mabilis na Delivery',
                'hi' => 'अधिक बचत • सबसे कम दाम • तेज़ डिलीवरी',
            ],
        ],
        'header_tagline' => [
            'ar' => 'كل ما تتمناه ... في مكان واحد.',
            'en' => 'Everything you wish for ... in one place.',
            'fil' => 'Lahat ng gusto mo ... sa isang lugar.',
            'hi' => 'जो कुछ भी आप चाहें ... एक ही जगह पर।',
        ],
    ];
}

/**
 * إن بقي النطاق فارغاً بعد الترحيل من الجدول القديم، نملأ القيم المدمجة (مرة واحدة عند أول تشغيل).
 */
function orange_catalog_seed_storefront_copy_defaults_if_empty(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'storefront_copy_lines')) {
        return;
    }

    require_once __DIR__ . '/countries.php';
    $countryId = orange_countries_default_id($pdo);
    $scoped = orange_table_has_column($pdo, 'storefront_copy_lines', 'country_id');
    if ($scoped && $countryId <= 0) {
        return;
    }

    $defaults = orange_catalog_builtin_storefront_copy_defaults();

    try {
        if (orange_catalog_count_storefront_copy_scope($pdo, 'home_hero', $scoped ? $countryId : null) === 0) {
            if ($scoped) {
                $ins = $pdo->prepare(
                    'INSERT INTO storefront_copy_lines (country_id, scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                     VALUES (?, ?, ?, 1, ?, ?, ?, ?)'
                );
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                     VALUES (?, ?, 1, ?, ?, ?, ?)'
                );
            }
            foreach ($defaults['home_hero'] as $idx => $line) {
                if ($scoped) {
                    $ins->execute([
                        $countryId,
                        'home_hero',
                        $idx + 1,
                        $line['ar'],
                        $line['en'],
                        $line['fil'],
                        $line['hi'],
                    ]);
                } else {
                    $ins->execute([
                        'home_hero',
                        $idx + 1,
                        $line['ar'],
                        $line['en'],
                        $line['fil'],
                        $line['hi'],
                    ]);
                }
            }
        }

        if (orange_catalog_count_storefront_copy_scope($pdo, 'header_tagline', $scoped ? $countryId : null) === 0) {
            $t = $defaults['header_tagline'];
            if ($scoped) {
                $ins = $pdo->prepare(
                    'INSERT INTO storefront_copy_lines (country_id, scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                     VALUES (?, ?, ?, 1, ?, ?, ?, ?)'
                );
                $ins->execute([$countryId, 'header_tagline', 1, $t['ar'], $t['en'], $t['fil'], $t['hi']]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                     VALUES (?, ?, 1, ?, ?, ?, ?)'
                );
                $ins->execute(['header_tagline', 1, $t['ar'], $t['en'], $t['fil'], $t['hi']]);
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] seed storefront_copy_lines: ' . $e->getMessage());
        }
    }
}

/**
 * ترحيل من storefront_home_hero إلى storefront_copy_lines — للدولة الافتراضية (الكويت) فقط؛ scoped بـ country_id بعد v52 (GAP-08).
 */
function orange_catalog_migrate_legacy_storefront_copy_lines(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'storefront_copy_lines')) {
        return;
    }

    require_once __DIR__ . '/countries.php';
    $scoped = orange_table_has_column($pdo, 'storefront_copy_lines', 'country_id');
    $countryId = $scoped ? orange_countries_default_id($pdo) : null;
    if ($scoped && ($countryId === null || $countryId <= 0)) {
        return;
    }

    try {
        if (orange_catalog_count_storefront_copy_scope($pdo, 'home_hero', $countryId) === 0
            && orange_table_exists($pdo, 'storefront_home_hero')) {
            $row = $pdo->query('SELECT * FROM storefront_home_hero WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if ($scoped) {
                    $ins = $pdo->prepare(
                        'INSERT INTO storefront_copy_lines (country_id, scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                         VALUES (?, ?, ?, 1, ?, ?, ?, ?)'
                    );
                } else {
                    $ins = $pdo->prepare(
                        'INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                         VALUES (?, ?, 1, ?, ?, ?, ?)'
                    );
                }
                for ($i = 1; $i <= 3; ++$i) {
                    $ar = trim((string) ($row['line_' . $i . '_ar'] ?? ''));
                    $en = trim((string) ($row['line_' . $i . '_en'] ?? ''));
                    $fil = trim((string) ($row['line_' . $i . '_fil'] ?? ''));
                    $hi = trim((string) ($row['line_' . $i . '_hi'] ?? ''));
                    if ($ar === '' && $en === '' && $fil === '' && $hi === '') {
                        continue;
                    }
                    if ($scoped) {
                        $ins->execute([$countryId, 'home_hero', $i, $ar, $en, $fil, $hi]);
                    } else {
                        $ins->execute(['home_hero', $i, $ar, $en, $fil, $hi]);
                    }
                }
            }
        }

        if (orange_catalog_count_storefront_copy_scope($pdo, 'header_tagline', $countryId) === 0
            && orange_table_exists($pdo, 'storefront_home_hero')) {
            $row = $pdo->query('SELECT * FROM storefront_home_hero WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $har = trim((string) ($row['header_tagline_ar'] ?? ''));
                $hen = trim((string) ($row['header_tagline_en'] ?? ''));
                $hfil = trim((string) ($row['header_tagline_fil'] ?? ''));
                $hhi = trim((string) ($row['header_tagline_hi'] ?? ''));
                if ($har !== '' || $hen !== '' || $hfil !== '' || $hhi !== '') {
                    if ($scoped) {
                        $ins = $pdo->prepare(
                            'INSERT INTO storefront_copy_lines (country_id, scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                             VALUES (?, ?, ?, 1, ?, ?, ?, ?)'
                        );
                        $ins->execute([$countryId, 'header_tagline', 1, $har, $hen, $hfil, $hhi]);
                    } else {
                        $ins = $pdo->prepare(
                            'INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                             VALUES (?, ?, 1, ?, ?, ?, ?)'
                        );
                        $ins->execute(['header_tagline', 1, $har, $hen, $hfil, $hhi]);
                    }
                }
            }
        }

        orange_catalog_seed_storefront_copy_defaults_if_empty($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] migrate storefront_copy_lines: ' . $e->getMessage());
        }
    }
}

/**
 * أساس تعدد الدول (المرحلة 1): countries + country_id على القنوات والمناطق — الكويت فقط نشطة في البداية.
 */
function orange_catalog_migrate_countries_foundation_v40(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_countries_foundation_v40';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'countries')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS countries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(8) NOT NULL,
                name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
                name_en VARCHAR(191) NOT NULL DEFAULT \'\',
                currency_code VARCHAR(8) NOT NULL DEFAULT \'\',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_countries_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    $seedCountries = [
        ['kw', 'الكويت', 'Kuwait', 'KWD', 1, 1],
        ['eg', 'مصر', 'Egypt', 'EGP', 2, 0],
        ['uae', 'الإمارات', 'United Arab Emirates', 'AED', 3, 0],
        ['ksa', 'السعودية', 'Saudi Arabia', 'SAR', 4, 0],
    ];
    foreach ($seedCountries as $sc) {
        $stChk = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
        $stChk->execute([$sc[0]]);
        if (!$stChk->fetch()) {
            $ins = $pdo->prepare(
                'INSERT INTO countries (code, name_ar, name_en, currency_code, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([$sc[0], $sc[1], $sc[2], $sc[3], $sc[4], $sc[5]]);
        }
    }

    $kwId = 0;
    $stKw = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
    $stKw->execute(['kw']);
    $kwRow = $stKw->fetch(PDO::FETCH_ASSOC);
    if ($kwRow) {
        $kwId = (int) $kwRow['id'];
    }

    if (orange_table_exists($pdo, 'channels')) {
        if (!orange_table_has_column($pdo, 'channels', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE channels ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
        }
        if (!orange_table_has_column($pdo, 'channels', 'channel_kind')) {
            orange_catalog_safe_exec(
                $pdo,
                "ALTER TABLE channels ADD COLUMN channel_kind VARCHAR(32) NOT NULL DEFAULT 'other' AFTER country_id"
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE channels SET country_id = ' . (int) $kwId . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
        orange_catalog_safe_exec(
            $pdo,
            "UPDATE channels SET channel_kind = 'web' WHERE channel_kind = 'other' AND LOWER(COALESCE(path_segment, '')) IN ('web', 'online')"
        );
        orange_catalog_safe_exec(
            $pdo,
            "UPDATE channels SET channel_kind = 'whatsapp' WHERE channel_kind = 'other' AND LOWER(COALESCE(path_segment, '')) = 'tiktok'"
        );

        foreach (['uq_channels_slug', 'uq_channels_path_segment'] as $ix) {
            $chk = $pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'channels\' AND INDEX_NAME = ? LIMIT 1'
            );
            $chk->execute([$ix]);
            if ($chk->fetchColumn()) {
                orange_catalog_safe_exec($pdo, 'ALTER TABLE channels DROP INDEX `' . str_replace('`', '``', $ix) . '`');
            }
        }
        $chkComp = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'channels\' AND INDEX_NAME = ? LIMIT 1'
        );
        $chkComp->execute(['uq_channels_country_slug']);
        if (!$chkComp->fetchColumn()) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE UNIQUE INDEX uq_channels_country_slug ON channels (country_id, slug)'
            );
        }
        $chkComp->execute(['uq_channels_country_path']);
        if (!$chkComp->fetchColumn()) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE UNIQUE INDEX uq_channels_country_path ON channels (country_id, path_segment)'
            );
        }
    }

    if (orange_table_exists($pdo, 'delivery_areas')) {
        if (!orange_table_has_column($pdo, 'delivery_areas', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_areas ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE delivery_areas SET country_id = ' . (int) $kwId . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
    }

    try {
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] countries_foundation_v40 marker: ' . $e->getMessage());
        }
    }
}

/**
 * محافظات التوصيل + ربط المناطق (المرحلة ب): delivery_governorates + delivery_areas.governorate_id.
 */
function orange_catalog_migrate_governorates_v41(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/delivery_areas.php';
    $marker = 'php_governorates_v41';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'delivery_governorates')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS delivery_governorates (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                country_id INT UNSIGNED NOT NULL,
                name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
                name_en VARCHAR(191) NOT NULL DEFAULT \'\',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_delivery_governorates_country (country_id),
                KEY idx_delivery_governorates_country_active (country_id, is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'delivery_areas')
        && !orange_table_has_column($pdo, 'delivery_areas', 'governorate_id')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE delivery_areas ADD COLUMN governorate_id INT UNSIGNED NULL DEFAULT NULL AFTER country_id'
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_delivery_areas_governorate_id ON delivery_areas (governorate_id)'
        );
    }

    if (orange_table_exists($pdo, 'countries') && orange_table_exists($pdo, 'delivery_governorates')) {
        $countryRows = $pdo->query('SELECT id FROM countries ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($countryRows as $cRow) {
            $cid = (int) ($cRow['id'] ?? 0);
            if ($cid > 0 && function_exists('orange_delivery_governorate_ensure_default')) {
                orange_delivery_governorate_ensure_default($pdo, $cid);
            }
        }
    }

    if (orange_table_exists($pdo, 'delivery_areas')
        && orange_table_has_column($pdo, 'delivery_areas', 'governorate_id')
        && orange_table_has_column($pdo, 'delivery_areas', 'country_id')
    ) {
        $orphans = $pdo->query(
            'SELECT DISTINCT country_id FROM delivery_areas WHERE governorate_id IS NULL AND country_id IS NOT NULL AND country_id > 0'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($orphans as $o) {
            $cid = (int) ($o['country_id'] ?? 0);
            if ($cid <= 0 || !function_exists('orange_delivery_governorate_ensure_default')) {
                continue;
            }
            $gid = orange_delivery_governorate_ensure_default($pdo, $cid);
            if ($gid > 0) {
                orange_catalog_safe_exec(
                    $pdo,
                    'UPDATE delivery_areas SET governorate_id = ' . (int) $gid
                    . ' WHERE country_id = ' . (int) $cid
                    . ' AND (governorate_id IS NULL OR governorate_id = 0)'
                );
            }
        }
        $noCountry = $pdo->query(
            'SELECT id FROM delivery_areas WHERE governorate_id IS NULL OR governorate_id = 0 LIMIT 1'
        )->fetchColumn();
        if ($noCountry && function_exists('orange_countries_default_id')) {
            $kwId = orange_countries_default_id($pdo);
            if ($kwId > 0) {
                $gid = orange_delivery_governorate_ensure_default($pdo, $kwId);
                if ($gid > 0) {
                    orange_catalog_safe_exec(
                        $pdo,
                        'UPDATE delivery_areas SET country_id = ' . (int) $kwId
                        . ', governorate_id = ' . (int) $gid
                        . ' WHERE governorate_id IS NULL OR governorate_id = 0'
                    );
                }
            }
        }
    }

    try {
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] governorates_v41 marker: ' . $e->getMessage());
        }
    }
}

/**
 * رموز أسواق الدول: اختصارات معروفة (uae, ksa) بدل ISO alpha-2 الداخلي (ae, sa) حيث يلزم.
 */
function orange_catalog_migrate_country_market_codes_v42(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_market_codes_v42';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (orange_table_exists($pdo, 'countries')) {
        $map = ['ae' => 'uae', 'sa' => 'ksa'];
        foreach ($map as $from => $to) {
            $stChk = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
            $stChk->execute([$to]);
            if ($stChk->fetch()) {
                continue;
            }
            $stUp = $pdo->prepare('UPDATE countries SET code = ? WHERE code = ? LIMIT 1');
            $stUp->execute([$to, $from]);
        }
    }

    try {
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country_market_codes_v42 marker: ' . $e->getMessage());
        }
    }
}

/** إعادة ترقيم sort_order للدول: 1، 2، 3، … (بدل 10، 20، 30، …). */
function orange_catalog_migrate_country_sort_renumber_v43(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_sort_renumber_v43';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (orange_table_exists($pdo, 'countries')) {
        $rows = $pdo->query('SELECT id FROM countries ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $next = 1;
        $up = $pdo->prepare('UPDATE countries SET sort_order = ? WHERE id = ? LIMIT 1');
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $up->execute([$next, $id]);
                ++$next;
            }
        }
    }

    try {
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country_sort_renumber_v43 marker: ' . $e->getMessage());
        }
    }
}

/**
 * مخازن لكل دولة + warehouse_variant_stock — ترحيل كميات الكويت من product_variants (بند 13.1).
 */
function orange_catalog_migrate_country_warehouses_v44(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_warehouses_v44';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'warehouses')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS warehouses (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                country_id INT UNSIGNED NOT NULL,
                name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
                name_en VARCHAR(191) NOT NULL DEFAULT \'\',
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_warehouses_country (country_id),
                KEY idx_warehouses_country_default (country_id, is_default, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'warehouse_variant_stock')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS warehouse_variant_stock (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                warehouse_id INT UNSIGNED NOT NULL,
                variant_id INT NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_wvs_wh_variant (warehouse_id, variant_id),
                KEY idx_wvs_variant (variant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'countries') && orange_table_exists($pdo, 'warehouses')) {
        $countries = $pdo->query(
            'SELECT id, name_ar, name_en FROM countries ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $insWh = $pdo->prepare(
            'INSERT INTO warehouses (country_id, name_ar, name_en, is_default, is_active, sort_order)
             SELECT ?, ?, ?, 1, 1, 1 FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM warehouses w WHERE w.country_id = ? LIMIT 1)'
        );
        foreach ($countries as $cRow) {
            if (!is_array($cRow)) {
                continue;
            }
            $cid = (int) ($cRow['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $nameAr = trim((string) ($cRow['name_ar'] ?? ''));
            $nameEn = trim((string) ($cRow['name_en'] ?? ''));
            if ($nameAr === '') {
                $nameAr = 'المخزن الرئيسي';
            }
            if ($nameEn === '') {
                $nameEn = 'Main warehouse';
            }
            $insWh->execute([
                $cid,
                $nameAr . ' — مخزن رئيسي',
                $nameEn . ' — main',
                $cid,
            ]);
        }
    }

    if (
        orange_table_exists($pdo, 'warehouse_variant_stock')
        && orange_table_exists($pdo, 'warehouses')
        && orange_table_exists($pdo, 'product_variants')
        && orange_table_exists($pdo, 'countries')
    ) {
        $stKw = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
        $stKw->execute(['kw']);
        $kwRow = $stKw->fetch(PDO::FETCH_ASSOC);
        $kwId = is_array($kwRow) ? (int) ($kwRow['id'] ?? 0) : 0;
        if ($kwId > 0) {
            $stWh = $pdo->prepare(
                'SELECT id FROM warehouses WHERE country_id = ? ORDER BY is_default DESC, id ASC LIMIT 1'
            );
            $stWh->execute([$kwId]);
            $whId = (int) ($stWh->fetchColumn() ?: 0);
            if ($whId > 0) {
                $variants = $pdo->query(
                    'SELECT id, stock_quantity FROM product_variants'
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $insStock = $pdo->prepare(
                    'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
                );
                foreach ($variants as $vRow) {
                    if (!is_array($vRow)) {
                        continue;
                    }
                    $vid = (int) ($vRow['id'] ?? 0);
                    if ($vid <= 0) {
                        continue;
                    }
                    $qty = (int) ($vRow['stock_quantity'] ?? 0);
                    $insStock->execute([$whId, $vid, $qty]);
                }
            }
        }
    }

    try {
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country_warehouses_v44 marker: ' . $e->getMessage());
        }
    }
}

/** معرّف دولة الكويت للترحيل والإصلاح — 0 إن لم تُوجَد. */
function orange_catalog_resolve_kuwait_country_id(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'countries')) {
        return 0;
    }
    $stKw = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
    $stKw->execute(['kw']);
    $kwRow = $stKw->fetch(PDO::FETCH_ASSOC);

    return is_array($kwRow) ? (int) ($kwRow['id'] ?? 0) : 0;
}

/** هل اكتملت أعمدة country_id لنطاق v45 (جداول التشغيل الأساسية)؟ */
function orange_catalog_country_scope_v45_satisfied(PDO $pdo): bool
{
    foreach (['customers', 'suppliers', 'purchases', 'products'] as $tbl) {
        if (orange_table_exists($pdo, $tbl) && !orange_table_has_column($pdo, $tbl, 'country_id')) {
            return false;
        }
    }
    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'country_id')) {
        return false;
    }
    if (orange_table_exists($pdo, 'stock_movements') && !orange_table_has_column($pdo, 'stock_movements', 'country_id')) {
        return false;
    }

    return true;
}

/** هل accounts.country_id موجود (v49)؟ */
function orange_catalog_country_gl_accounts_v49_satisfied(PDO $pdo): bool
{
    return !orange_table_exists($pdo, 'accounts')
        || orange_table_has_column($pdo, 'accounts', 'country_id');
}

/**
 * ربط السجلات القديمة (country_id NULL/0) بدولة الكويت — بعد إضافة العمود.
 */
function orange_catalog_backfill_kuwait_country_ids(PDO $pdo, int $kwId): void
{
    if ($kwId <= 0) {
        return;
    }
    $tables = [
        'customers', 'suppliers', 'purchases', 'products',
        'journal_vouchers', 'channels', 'delivery_areas',
        'cart_promotions', 'cart_gift_promotions', 'cart_bogo_promotions', 'cart_combo_promotions',
        'storefront_accounts', 'admins',
        'orange_gl_account_settings', 'orange_gl_setting_alloc',
        'orders', 'stock_movements', 'accounts',
    ];
    foreach ($tables as $tbl) {
        if (!orange_table_exists($pdo, $tbl) || !orange_table_has_column($pdo, $tbl, 'country_id')) {
            continue;
        }
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE ' . $tbl . ' SET country_id = ' . (int) $kwId
            . ' WHERE country_id IS NULL OR country_id = 0'
        );
    }
}

/**
 * إصلاح idempotent: يضيف country_id ويملأ الكويت — يُشغَّل كل مرة حتى لو سُجّل الترحيل سابقاً وفشل ALTER.
 */
function orange_catalog_ensure_country_id_columns(PDO $pdo): void
{
    $kwId = orange_catalog_resolve_kuwait_country_id($pdo);

    $tablesCountryOnly = [
        'customers', 'suppliers', 'purchases', 'products',
        'journal_vouchers', 'channels', 'delivery_areas',
        'cart_promotions', 'cart_gift_promotions', 'cart_bogo_promotions', 'cart_combo_promotions',
        'storefront_accounts', 'admins',
        'orange_gl_account_settings', 'orange_gl_setting_alloc',
    ];
    foreach ($tablesCountryOnly as $tbl) {
        if (!orange_table_exists($pdo, $tbl)) {
            continue;
        }
        if (!orange_table_has_column($pdo, $tbl, 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE ' . $tbl . ' ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_' . $tbl . '_country_id ON ' . $tbl . ' (country_id)'
            );
            orange_schema_invalidate_column_check($tbl, 'country_id');
        }
    }

    if (orange_table_exists($pdo, 'orders')) {
        if (!orange_table_has_column($pdo, 'orders', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orders ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_orders_country_id ON orders (country_id)');
            orange_schema_invalidate_column_check('orders', 'country_id');
        }
        if (!orange_table_has_column($pdo, 'orders', 'warehouse_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orders ADD COLUMN warehouse_id INT UNSIGNED NULL DEFAULT NULL AFTER country_id'
            );
            orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_orders_warehouse_id ON orders (warehouse_id)');
            orange_schema_invalidate_column_check('orders', 'warehouse_id');
        }
        if ($kwId > 0 && orange_table_exists($pdo, 'warehouses')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orders o
                 INNER JOIN warehouses w ON w.country_id = ' . (int) $kwId . ' AND w.is_default = 1
                 SET o.warehouse_id = w.id
                 WHERE (o.warehouse_id IS NULL OR o.warehouse_id = 0)
                   AND (o.country_id = ' . (int) $kwId . ' OR o.country_id IS NULL)'
            );
        }
    }

    if (orange_table_exists($pdo, 'stock_movements')) {
        if (!orange_table_has_column($pdo, 'stock_movements', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE stock_movements ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_stock_movements_country_id ON stock_movements (country_id)');
            orange_schema_invalidate_column_check('stock_movements', 'country_id');
        }
        if (!orange_table_has_column($pdo, 'stock_movements', 'warehouse_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE stock_movements ADD COLUMN warehouse_id INT UNSIGNED NULL DEFAULT NULL AFTER country_id'
            );
            orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_stock_movements_warehouse_id ON stock_movements (warehouse_id)');
            orange_schema_invalidate_column_check('stock_movements', 'warehouse_id');
        }
        if ($kwId > 0 && orange_table_exists($pdo, 'warehouses')) {
                orange_catalog_safe_exec(
                    $pdo,
                    'UPDATE stock_movements sm
                     INNER JOIN warehouses w ON w.country_id = ' . (int) $kwId . ' AND w.is_default = 1
                     SET sm.warehouse_id = w.id
                     WHERE (sm.warehouse_id IS NULL OR sm.warehouse_id = 0)'
                );
        }
    }

    if (orange_table_exists($pdo, 'accounts')) {
        if (!orange_table_has_column($pdo, 'accounts', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE accounts ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_accounts_country_id ON accounts (country_id)'
            );
            orange_schema_invalidate_column_check('accounts', 'country_id');
        }
    }

    orange_catalog_backfill_kuwait_country_ids($pdo, $kwId);
}

/** مرة واحدة لكل طلب — يُستدعى حتى عند تخطّي بوابة APCu/OK flag. */
function orange_catalog_ensure_country_id_columns_once(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    orange_catalog_ensure_country_id_columns($pdo);
}

/** تسجيل علامة ترحيل إن لم تكن مسجّلة (إصلاح سجل كاذب). */
function orange_catalog_schema_insert_migration_marker(PDO $pdo, string $filename): void
{
    require_once __DIR__ . '/schema_migrations.php';
    orange_schema_migrations_ensure_table($pdo);
    if (orange_schema_migration_already_applied($pdo, $filename)) {
        return;
    }
    try {
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$filename]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] migration marker insert ' . $filename . ': ' . $e->getMessage());
        }
    }
}

/**
 * v50 — إصلاح country_id + مزامنة سجل الترحيل مع orange_db.sql / السيرفر.
 */
function orange_catalog_migrate_country_repair_v50(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_repair_v50';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    orange_catalog_ensure_country_id_columns($pdo);

    if (orange_catalog_country_scope_v45_satisfied($pdo)) {
        orange_catalog_schema_insert_migration_marker($pdo, 'php_country_scope_v45');
    }
    if (orange_catalog_country_gl_accounts_v49_satisfied($pdo)) {
        orange_catalog_schema_insert_migration_marker($pdo, 'php_country_gl_accounts_v49');
    }

    if (orange_catalog_country_scope_v45_satisfied($pdo)
        && orange_catalog_country_gl_accounts_v49_satisfied($pdo)) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);
    }
}

/**
 * v51 — تفعيل الأقسام per country (department_countries) مع backfill من is_active العام.
 */
function orange_catalog_migrate_department_countries_v51(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_department_countries_v51';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'department_countries')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE department_countries (
                department_id INT NOT NULL,
                country_id INT UNSIGNED NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (department_id, country_id),
                KEY idx_department_countries_country (country_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'departments')
        && orange_table_exists($pdo, 'countries')
        && orange_table_exists($pdo, 'department_countries')) {
        $depts = $pdo->query('SELECT id, is_active FROM departments')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $countries = $pdo->query('SELECT id FROM countries')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO department_countries (department_id, country_id, is_active) VALUES (?, ?, ?)'
        );
        foreach ($depts as $d) {
            $did = (int) ($d['id'] ?? 0);
            if ($did <= 0) {
                continue;
            }
            $masterActive = (int) ($d['is_active'] ?? 0) === 1;
            foreach ($countries as $c) {
                $cid = (int) ($c['id'] ?? 0);
                if ($cid <= 0) {
                    continue;
                }
                $ins->execute([$did, $cid, $masterActive ? 1 : 0]);
            }
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * يضمن عمود country_id على journal_types (آمن لإعادة التشغيل — يُستدعى من v52 و v59).
 */
function orange_catalog_ensure_journal_types_country_id_column(PDO $pdo): bool
{
    if (!orange_table_exists($pdo, 'journal_types')) {
        return false;
    }
    if (orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        return true;
    }

    require_once __DIR__ . '/countries.php';
    $kwId = orange_countries_default_id($pdo);

    orange_catalog_safe_exec(
        $pdo,
        'ALTER TABLE journal_types ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
    );
    if ($kwId > 0) {
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE journal_types SET country_id = ' . (int) $kwId . ' WHERE country_id IS NULL OR country_id = 0'
        );
    }
    try {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE journal_types DROP INDEX uq_journal_types_code');
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] journal_types drop uq_journal_types_code: ' . $e->getMessage());
        }
    }
    orange_catalog_safe_exec($pdo, 'ALTER TABLE journal_types MODIFY country_id INT UNSIGNED NOT NULL');
    orange_catalog_safe_exec(
        $pdo,
        'CREATE UNIQUE INDEX uq_journal_types_country_code ON journal_types (country_id, code)'
    );
    orange_catalog_safe_exec(
        $pdo,
        'CREATE INDEX idx_journal_types_country_sort ON journal_types (country_id, sort_order)'
    );
    orange_schema_invalidate_column_check('journal_types', 'country_id');

    return orange_table_has_column($pdo, 'journal_types', 'country_id');
}

/**
 * v52 — journal_types، fiscal_years، company_settings، storefront_copy_lines، merge_requests per country.
 */
function orange_catalog_migrate_country_admin_settings_v52(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_admin_settings_v52';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    require_once __DIR__ . '/countries.php';
    $kwId = orange_countries_default_id($pdo);

    orange_catalog_ensure_journal_types_country_id_column($pdo);

    if (orange_table_exists($pdo, 'fiscal_years') && !orange_table_has_column($pdo, 'fiscal_years', 'country_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE fiscal_years ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
        );
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE fiscal_years SET country_id = ' . (int) $kwId . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
        orange_catalog_safe_exec($pdo, 'ALTER TABLE fiscal_years MODIFY country_id INT UNSIGNED NOT NULL');
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_fiscal_years_country_range ON fiscal_years (country_id, start_date, end_date)'
        );
    }

    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'country_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE company_settings ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
        );
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE company_settings SET country_id = ' . (int) $kwId . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
        orange_catalog_safe_exec($pdo, 'ALTER TABLE company_settings MODIFY country_id INT UNSIGNED NOT NULL');
        try {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE UNIQUE INDEX uq_company_settings_country ON company_settings (country_id)'
            );
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] v52 uq_company_settings_country: ' . $e->getMessage());
            }
        }
    }

    if (orange_table_exists($pdo, 'storefront_copy_lines') && !orange_table_has_column($pdo, 'storefront_copy_lines', 'country_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_copy_lines ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
        );
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE storefront_copy_lines SET country_id = ' . (int) $kwId . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
        orange_catalog_safe_exec($pdo, 'ALTER TABLE storefront_copy_lines MODIFY country_id INT UNSIGNED NOT NULL');
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_storefront_copy_country_scope ON storefront_copy_lines (country_id, scope, is_active, sort_order)'
        );
    }

    if (orange_table_exists($pdo, 'storefront_phone_merge_requests')
        && !orange_table_has_column($pdo, 'storefront_phone_merge_requests', 'country_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_phone_merge_requests ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
        );
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE storefront_phone_merge_requests SET country_id = ' . (int) $kwId
                . ' WHERE country_id IS NULL OR country_id = 0'
            );
            if (orange_table_exists($pdo, 'channels') && orange_table_has_column($pdo, 'channels', 'country_id')) {
                orange_catalog_safe_exec(
                    $pdo,
                    'UPDATE storefront_phone_merge_requests r
                     INNER JOIN channels c ON c.slug = r.proposed_channel_slug AND c.country_id > 0
                     SET r.country_id = c.country_id
                     WHERE r.proposed_channel_slug IS NOT NULL AND TRIM(r.proposed_channel_slug) <> \'\''
                );
            }
        }
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE storefront_phone_merge_requests MODIFY country_id INT UNSIGNED NOT NULL DEFAULT 0'
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_spmr_country ON storefront_phone_merge_requests (country_id, expires_at)'
        );
    }

    if ($kwId > 0 && orange_table_exists($pdo, 'countries')) {
        require_once __DIR__ . '/country_provision.php';
        $countryRows = $pdo->query('SELECT id FROM countries')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($countryRows as $crow) {
            $cid = (int) ($crow['id'] ?? 0);
            if ($cid <= 0 || $cid === $kwId) {
                continue;
            }
            orange_country_copy_journal_types_from_source($pdo, $cid, $kwId);
            orange_country_copy_fiscal_years_from_source($pdo, $cid, $kwId);
            orange_country_copy_company_settings_from_source($pdo, $cid, $kwId);
            orange_country_copy_storefront_copy_lines_from_source($pdo, $cid, $kwId);
        }
    }

    if (orange_table_exists($pdo, 'journal_types') && orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        require_once __DIR__ . '/journal_types.php';
        if (orange_table_exists($pdo, 'countries')) {
            $countryRows = $pdo->query('SELECT id FROM countries')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($countryRows as $crow) {
                $cid = (int) ($crow['id'] ?? 0);
                if ($cid > 0) {
                    try {
                        orange_journal_types_merge_canonical_defaults($pdo, $cid);
                    } catch (Throwable $e) {
                        if (function_exists('error_log')) {
                            error_log('[orange] v52 journal_types merge cid=' . $cid . ': ' . $e->getMessage());
                        }
                    }
                }
            }
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v53 — orange_gl_journal_type_rules per country (GAP-01).
 */
function orange_catalog_migrate_gl_journal_type_rules_country_v53(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_gl_journal_type_rules_country_v53';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    require_once __DIR__ . '/countries.php';
    $kwId = orange_countries_default_id($pdo);

    if (!orange_table_has_column($pdo, 'orange_gl_journal_type_rules', 'country_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_journal_type_rules ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
        );
        orange_schema_invalidate_column_check('orange_gl_journal_type_rules', 'country_id');

        if (orange_table_exists($pdo, 'journal_types')
            && orange_table_has_column($pdo, 'journal_types', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orange_gl_journal_type_rules r
                 INNER JOIN journal_types jt ON jt.id = r.journal_type_id
                 SET r.country_id = jt.country_id
                 WHERE r.country_id IS NULL OR r.country_id = 0'
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orange_gl_journal_type_rules SET country_id = ' . (int) $kwId
                . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orange_gl_journal_type_rules MODIFY country_id INT UNSIGNED NOT NULL');
        try {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE orange_gl_journal_type_rules DROP INDEX uq_ojtr_jt_terms');
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] v53 drop uq_ojtr_jt_terms: ' . $e->getMessage());
            }
        }
        orange_catalog_safe_exec(
            $pdo,
            'CREATE UNIQUE INDEX uq_ojtr_country_jt_terms ON orange_gl_journal_type_rules (country_id, journal_type_id, payment_terms)'
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_ojtr_country ON orange_gl_journal_type_rules (country_id)'
        );
    }

    if ($kwId > 0 && orange_table_exists($pdo, 'countries')) {
        require_once __DIR__ . '/country_provision.php';
        $countryRows = $pdo->query('SELECT id FROM countries')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($countryRows as $crow) {
            $cid = (int) ($crow['id'] ?? 0);
            if ($cid <= 0 || $cid === $kwId) {
                continue;
            }
            orange_country_copy_gl_journal_type_rules_from_source($pdo, $cid, $kwId);
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v54 — إصلاح journal_type_id في orange_gl_account_settings بعد فصل الدول (GAP-02).
 */
function orange_catalog_migrate_gl_settings_journal_type_remap_v54(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_gl_settings_journal_type_remap_v54';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'orange_gl_account_settings')
        || !orange_table_has_column($pdo, 'orange_gl_account_settings', 'journal_type_id')
        || !orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')
        || !orange_table_exists($pdo, 'journal_types')
        || !orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    try {
        $pdo->exec(
            'UPDATE orange_gl_account_settings g
             INNER JOIN journal_types jt_src ON jt_src.id = g.journal_type_id
             INNER JOIN journal_types jt_tgt ON jt_tgt.country_id = g.country_id AND jt_tgt.code = jt_src.code
             SET g.journal_type_id = jt_tgt.id
             WHERE g.journal_type_id IS NOT NULL AND g.journal_type_id > 0
               AND jt_src.country_id <> g.country_id'
        );
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] v54 gl_settings journal_type remap: ' . $e->getMessage());
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v55 — is_country_default على channels (قناة رئيسية لكل دولة — Geo + جذر الموقع).
 */
function orange_catalog_migrate_channel_country_default_v55(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_channel_country_default_v55';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (orange_table_exists($pdo, 'channels')
        && !orange_table_has_column($pdo, 'channels', 'is_country_default')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE channels ADD COLUMN is_country_default TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
        );
    }

    if (orange_table_exists($pdo, 'channels')
        && orange_table_has_column($pdo, 'channels', 'is_country_default')
        && orange_table_has_column($pdo, 'channels', 'country_id')) {
        try {
            $countries = $pdo->query('SELECT DISTINCT country_id FROM channels WHERE country_id IS NOT NULL AND country_id > 0')
                ->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($countries as $cid) {
                $countryId = (int) $cid;
                if ($countryId <= 0) {
                    continue;
                }
                $has = $pdo->prepare(
                    'SELECT 1 FROM channels WHERE country_id = ? AND is_country_default = 1 LIMIT 1'
                );
                $has->execute([$countryId]);
                if ($has->fetch()) {
                    continue;
                }
                $first = $pdo->prepare(
                    'SELECT id FROM channels WHERE country_id = ? AND is_active = 1 ORDER BY id ASC LIMIT 1'
                );
                $first->execute([$countryId]);
                $chId = (int) ($first->fetchColumn() ?: 0);
                if ($chId > 0) {
                    $pdo->prepare('UPDATE channels SET is_country_default = 1 WHERE id = ?')->execute([$chId]);
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] v55 channel country default backfill: ' . $e->getMessage());
            }
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v56 — currency_code على سندات GL والمستندات التجارية (عملة محلية لكل دولة).
 */
function orange_catalog_migrate_document_currency_v56(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/currency.php';
    $marker = 'php_document_currency_v56';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $tables = [
        'journal_vouchers',
        'orders',
        'purchases',
        'sales_returns',
        'purchase_returns',
    ];
    foreach ($tables as $tbl) {
        if (!orange_table_exists($pdo, $tbl)) {
            continue;
        }
        if (!orange_table_has_column($pdo, $tbl, 'currency_code')) {
            $after = orange_table_has_column($pdo, $tbl, 'country_id') ? 'country_id' : 'id';
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE ' . $tbl . ' ADD COLUMN currency_code CHAR(3) NULL DEFAULT NULL AFTER ' . $after
            );
        }
    }

    if (orange_table_exists($pdo, 'journal_vouchers')
        && orange_table_has_column($pdo, 'journal_vouchers', 'currency_code')
        && orange_table_has_column($pdo, 'journal_vouchers', 'country_id')
        && orange_table_exists($pdo, 'countries')) {
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE journal_vouchers j
             INNER JOIN countries c ON c.id = j.country_id
             SET j.currency_code = UPPER(TRIM(c.currency_code))
             WHERE (j.currency_code IS NULL OR j.currency_code = \'\')
               AND c.currency_code IS NOT NULL AND TRIM(c.currency_code) <> \'\''
        );
    }

    $docTables = ['orders', 'purchases', 'sales_returns', 'purchase_returns'];
    foreach ($docTables as $tbl) {
        if (!orange_table_exists($pdo, $tbl)
            || !orange_table_has_column($pdo, $tbl, 'currency_code')
            || !orange_table_has_column($pdo, $tbl, 'country_id')
            || !orange_table_exists($pdo, 'countries')) {
            continue;
        }
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE ' . $tbl . ' d
             INNER JOIN countries c ON c.id = d.country_id
             SET d.currency_code = UPPER(TRIM(c.currency_code))
             WHERE (d.currency_code IS NULL OR d.currency_code = \'\')
               AND c.currency_code IS NOT NULL AND TRIM(c.currency_code) <> \'\''
        );
    }

    if (orange_table_exists($pdo, 'journal_vouchers')
        && orange_table_has_column($pdo, 'journal_vouchers', 'currency_code')) {
        $kwCur = 'KWD';
        if (orange_table_exists($pdo, 'countries')) {
            $stKw = $pdo->prepare('SELECT currency_code FROM countries WHERE code = ? LIMIT 1');
            $stKw->execute(['kw']);
            $kwRow = strtoupper(trim((string) ($stKw->fetchColumn() ?: '')));
            if ($kwRow !== '' && preg_match('/^[A-Z]{3}$/', $kwRow)) {
                $kwCur = $kwRow;
            }
        }
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE journal_vouchers SET currency_code = ' . $pdo->quote($kwCur)
            . ' WHERE currency_code IS NULL OR currency_code = \'\''
        );
    }

    foreach ($docTables as $tbl) {
        if (!orange_table_exists($pdo, $tbl)
            || !orange_table_has_column($pdo, $tbl, 'currency_code')) {
            continue;
        }
        $kwCur = 'KWD';
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE ' . $tbl . ' SET currency_code = ' . $pdo->quote($kwCur)
            . ' WHERE currency_code IS NULL OR currency_code = \'\''
        );
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v84 — تاريخ المستند (الفاتورة) القابل للضبط على المستندات التجارية اليدوية.
 * منفصل عن created_at (تاريخ الإدخال للتدقيق، يبقى تلقائياً). يُستخدم كتاريخ ترحيل
 * القيد المحاسبي وحركة المخزون على: المشتريات، فاتورة المبيعات (orders)، مردود
 * المبيعات، مردود المشتريات. فاتورة الأونلاين تبقى مشتقّة وتُرحَّل عند «إنشاء القيود».
 */
function orange_catalog_migrate_document_business_date_v84(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_document_business_date_v84';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $tables = ['orders', 'purchases', 'sales_returns', 'purchase_returns'];
    foreach ($tables as $tbl) {
        if (!orange_table_exists($pdo, $tbl)) {
            continue;
        }
        if (!orange_table_has_column($pdo, $tbl, 'document_date')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE ' . $tbl . ' ADD COLUMN document_date DATE NULL DEFAULT NULL'
            );
            orange_schema_invalidate_column_check($tbl, 'document_date');
        }
        if (orange_table_has_column($pdo, $tbl, 'document_date')
            && orange_table_has_column($pdo, $tbl, 'created_at')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE ' . $tbl . ' SET document_date = DATE(created_at)'
                . ' WHERE document_date IS NULL AND created_at IS NOT NULL'
            );
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v57 — مزامنة currency_code للموردين (وعملاء إن وُجد) مع عملة country_id.
 */
function orange_catalog_migrate_supplier_country_currency_v57(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/currency.php';
    $marker = 'php_supplier_country_currency_v57';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $tables = ['suppliers'];
    if (orange_table_exists($pdo, 'customers') && orange_table_has_column($pdo, 'customers', 'currency_code')) {
        $tables[] = 'customers';
    }

    if (!orange_table_exists($pdo, 'countries')) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    $countryRows = $pdo->query('SELECT id, code, currency_code FROM countries')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($countryRows as $cRow) {
        $countryId = (int) ($cRow['id'] ?? 0);
        if ($countryId <= 0) {
            continue;
        }
        $cur = strtoupper(trim((string) ($cRow['currency_code'] ?? '')));
        if ($cur === '' || !preg_match('/^[A-Z]{3}$/', $cur)) {
            $cur = orange_countries_currency_for_code((string) ($cRow['code'] ?? ''));
        }
        if ($cur === '' || !preg_match('/^[A-Z]{3}$/', $cur)) {
            continue;
        }
        foreach ($tables as $tbl) {
            if (!orange_table_exists($pdo, $tbl)
                || !orange_table_has_column($pdo, $tbl, 'currency_code')
                || !orange_table_has_column($pdo, $tbl, 'country_id')) {
                continue;
            }
            try {
                $pdo->prepare(
                    'UPDATE ' . $tbl . ' SET currency_code = ? WHERE country_id = ?'
                )->execute([$cur, $countryId]);
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] v57 ' . $tbl . ' currency sync: ' . $e->getMessage());
                }
            }
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v58 — إزالة أنواع اليوميات المبذورة لغير الكويت (ترحيل v52 كان ينسخ/يدمج للكل).
 */
function orange_catalog_migrate_journal_types_non_default_purge_v58(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_journal_types_non_default_purge_v58';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'journal_types')) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    orange_catalog_ensure_journal_types_country_id_column($pdo);

    if (!orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        if (function_exists('error_log')) {
            error_log('[orange] v58 journal_types purge skipped: country_id column missing');
        }
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    require_once __DIR__ . '/journal_types.php';
    try {
        $purged = orange_journal_types_purge_non_auto_seed_countries($pdo);
        if ($purged !== [] && function_exists('error_log')) {
            error_log('[orange] v58 journal_types purge non-default: ' . json_encode($purged, JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] v58 journal_types purge: ' . $e->getMessage());
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v59 — إصلاح country_id إن غاب + إعادة حذف أنواع غير الكويت (بعد v58 أو v52 ناقص).
 */
function orange_catalog_migrate_journal_types_country_scope_repair_v59(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_journal_types_country_scope_repair_v59';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'journal_types')) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    orange_catalog_ensure_journal_types_country_id_column($pdo);

    if (orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        require_once __DIR__ . '/journal_types.php';
        try {
            $purged = orange_journal_types_purge_non_auto_seed_countries($pdo);
            if ($purged !== [] && function_exists('error_log')) {
                error_log('[orange] v59 journal_types purge non-default: ' . json_encode($purged, JSON_UNESCAPED_UNICODE));
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] v59 journal_types purge: ' . $e->getMessage());
            }
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * ترحيلات journal_types per country — تُستدعى من المسار السريع والبطيء (علامات idempotent).
 */
function orange_catalog_ensure_journal_types_country_scope(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'journal_types')) {
        return;
    }
    orange_catalog_ensure_journal_types_country_id_column($pdo);
    orange_schema_invalidate_column_check('journal_types', 'country_id');
    orange_catalog_migrate_journal_types_non_default_purge_v58($pdo);
    orange_catalog_migrate_journal_types_country_scope_repair_v59($pdo);
    orange_catalog_migrate_journal_types_strip_non_kw_v60($pdo);
    orange_catalog_migrate_journal_types_strip_non_kw_v61($pdo);
}

/**
 * v60 — حذف صريح لكل journal_types خارج الكويت (إصلاح v52 + عرض موحّد خاطئ).
 */
function orange_catalog_migrate_journal_types_strip_non_kw_v60(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_journal_types_strip_non_kw_v60';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'journal_types')) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    orange_catalog_ensure_journal_types_country_id_column($pdo);
    orange_schema_invalidate_column_check('journal_types', 'country_id');

    if (orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        require_once __DIR__ . '/journal_types.php';
        try {
            $deleted = orange_journal_types_strip_all_non_default_countries($pdo);
            if ($deleted > 0 && function_exists('error_log')) {
                error_log('[orange] v60 journal_types strip non-KW deleted=' . $deleted);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] v60 journal_types strip: ' . $e->getMessage());
            }
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v61 — إعادة حذف journal_types خارج الكويت (v58–v60 لم تكن على المسار السريع للسيرفر).
 */
function orange_catalog_migrate_journal_types_strip_non_kw_v61(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_journal_types_strip_non_kw_v61';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'journal_types')) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    orange_catalog_ensure_journal_types_country_id_column($pdo);
    orange_schema_invalidate_column_check('journal_types', 'country_id');

    if (orange_table_has_column($pdo, 'journal_types', 'country_id')) {
        require_once __DIR__ . '/journal_types.php';
        try {
            $deleted = orange_journal_types_strip_all_non_default_countries($pdo);
            if ($deleted > 0 && function_exists('error_log')) {
                error_log('[orange] v61 journal_types strip non-KW deleted=' . $deleted);
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] v61 journal_types strip: ' . $e->getMessage());
            }
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * بند 13.9(2): country_id (+ warehouse_id على orders/stock_movements) — ترحيل الكويت.
 */
function orange_catalog_migrate_country_scope_v45(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_scope_v45';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        if (!orange_catalog_country_scope_v45_satisfied($pdo)) {
            orange_catalog_ensure_country_id_columns($pdo);
        }

        return;
    }

    $kwId = 0;
    if (orange_table_exists($pdo, 'countries')) {
        $stKw = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
        $stKw->execute(['kw']);
        $kwRow = $stKw->fetch(PDO::FETCH_ASSOC);
        if (is_array($kwRow)) {
            $kwId = (int) ($kwRow['id'] ?? 0);
        }
    }

    $tablesCountryOnly = ['customers', 'suppliers', 'purchases', 'products'];
    foreach ($tablesCountryOnly as $tbl) {
        if (!orange_table_exists($pdo, $tbl)) {
            continue;
        }
        if (!orange_table_has_column($pdo, $tbl, 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE ' . $tbl . ' ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_' . $tbl . '_country_id ON ' . $tbl . ' (country_id)'
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE ' . $tbl . ' SET country_id = ' . (int) $kwId
                . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
    }

    if (orange_table_exists($pdo, 'orders')) {
        if (!orange_table_has_column($pdo, 'orders', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orders ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_orders_country_id ON orders (country_id)');
        }
        if (!orange_table_has_column($pdo, 'orders', 'warehouse_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orders ADD COLUMN warehouse_id INT UNSIGNED NULL DEFAULT NULL AFTER country_id'
            );
            orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_orders_warehouse_id ON orders (warehouse_id)');
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orders SET country_id = ' . (int) $kwId . ' WHERE country_id IS NULL OR country_id = 0'
            );
            if (orange_table_exists($pdo, 'warehouses')) {
                orange_catalog_safe_exec(
                    $pdo,
                    'UPDATE orders o
                     INNER JOIN warehouses w ON w.country_id = ' . (int) $kwId . ' AND w.is_default = 1
                     SET o.warehouse_id = w.id
                     WHERE (o.warehouse_id IS NULL OR o.warehouse_id = 0)
                       AND (o.country_id = ' . (int) $kwId . ' OR o.country_id IS NULL)'
                );
            }
        }
    }

    if (orange_table_exists($pdo, 'stock_movements')) {
        if (!orange_table_has_column($pdo, 'stock_movements', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE stock_movements ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_stock_movements_country_id ON stock_movements (country_id)');
        }
        if (!orange_table_has_column($pdo, 'stock_movements', 'warehouse_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE stock_movements ADD COLUMN warehouse_id INT UNSIGNED NULL DEFAULT NULL AFTER country_id'
            );
            orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_stock_movements_warehouse_id ON stock_movements (warehouse_id)');
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE stock_movements SET country_id = ' . (int) $kwId . ' WHERE country_id IS NULL OR country_id = 0'
            );
            if (orange_table_exists($pdo, 'warehouses')) {
                orange_catalog_safe_exec(
                    $pdo,
                    'UPDATE stock_movements sm
                     INNER JOIN warehouses w ON w.country_id = ' . (int) $kwId . ' AND w.is_default = 1
                     SET sm.warehouse_id = w.id
                     WHERE (sm.warehouse_id IS NULL OR sm.warehouse_id = 0)'
                );
            }
        }
    }

    try {
        if (orange_catalog_country_scope_v45_satisfied($pdo)) {
            $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
            $ins->execute([$marker]);
        } elseif (function_exists('error_log')) {
            error_log('[orange] country_scope_v45: marker skipped — country_id columns still missing after migration');
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country_scope_v45 marker: ' . $e->getMessage());
        }
    }
}

/**
 * بند 13.6 + 13.8: storefront_accounts.country_id + admins.country_id.
 */
function orange_catalog_migrate_country_accounts_v46(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_accounts_v46';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $kwId = 0;
    if (orange_table_exists($pdo, 'countries')) {
        $stKw = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
        $stKw->execute(['kw']);
        $kwRow = $stKw->fetch(PDO::FETCH_ASSOC);
        if (is_array($kwRow)) {
            $kwId = (int) ($kwRow['id'] ?? 0);
        }
    }

    if (orange_table_exists($pdo, 'storefront_accounts')) {
        if (!orange_table_has_column($pdo, 'storefront_accounts', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE storefront_accounts ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_storefront_accounts_country_id ON storefront_accounts (country_id)'
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE storefront_accounts SET country_id = ' . (int) $kwId
                . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
        orange_catalog_safe_exec($pdo, 'ALTER TABLE storefront_accounts DROP INDEX uq_storefront_accounts_email');
        orange_catalog_safe_exec(
            $pdo,
            'CREATE UNIQUE INDEX uq_storefront_accounts_email_country ON storefront_accounts (email, country_id)'
        );
    }

    if (orange_table_exists($pdo, 'admins')) {
        if (!orange_table_has_column($pdo, 'admins', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE admins ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_admins_country_id ON admins (country_id)'
            );
        }
    }

    try {
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country_accounts_v46 marker: ' . $e->getMessage());
        }
    }
}

/**
 * بند 13.5: journal_vouchers.country_id — فصل GL بالدولة.
 */
function orange_catalog_migrate_country_gl_v47(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_gl_v47';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $kwId = 0;
    if (orange_table_exists($pdo, 'countries')) {
        $stKw = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
        $stKw->execute(['kw']);
        $kwRow = $stKw->fetch(PDO::FETCH_ASSOC);
        if (is_array($kwRow)) {
            $kwId = (int) ($kwRow['id'] ?? 0);
        }
    }

    if (orange_table_exists($pdo, 'journal_vouchers')) {
        if (!orange_table_has_column($pdo, 'journal_vouchers', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE journal_vouchers ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER fiscal_year_id'
            );
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_journal_vouchers_country_id ON journal_vouchers (country_id)'
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE journal_vouchers SET country_id = ' . (int) $kwId
                . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
    }

    try {
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country_gl_v47 marker: ' . $e->getMessage());
        }
    }
}

/**
 * بند 13: country_id على جداول عروض السلة — ترحيل الكويت.
 */
function orange_catalog_migrate_country_cart_promotions_v48(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_cart_promotions_v48';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $kwId = 0;
    if (orange_table_exists($pdo, 'countries')) {
        $stKw = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
        $stKw->execute(['kw']);
        $kwRow = $stKw->fetch(PDO::FETCH_ASSOC);
        if (is_array($kwRow)) {
            $kwId = (int) ($kwRow['id'] ?? 0);
        }
    }

    $tables = ['cart_promotions', 'cart_gift_promotions', 'cart_bogo_promotions', 'cart_combo_promotions'];
    foreach ($tables as $tbl) {
        if (!orange_table_exists($pdo, $tbl)) {
            continue;
        }
        if (!orange_table_has_column($pdo, $tbl, 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE ' . $tbl . ' ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_' . $tbl . '_country_id ON ' . $tbl . ' (country_id)'
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE ' . $tbl . ' SET country_id = ' . (int) $kwId
                . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
    }

    try {
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country_cart_promotions_v48 marker: ' . $e->getMessage());
        }
    }
}

/**
 * بند 13.5 — مرحلة 18: accounts.country_id + إعدادات القيود التلقائية per country.
 */
function orange_catalog_migrate_country_gl_accounts_v49(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_country_gl_accounts_v49';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        if (!orange_catalog_country_gl_accounts_v49_satisfied($pdo)) {
            orange_catalog_ensure_country_id_columns($pdo);
        }

        return;
    }

    $kwId = 0;
    if (orange_table_exists($pdo, 'countries')) {
        $stKw = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
        $stKw->execute(['kw']);
        $kwRow = $stKw->fetch(PDO::FETCH_ASSOC);
        if (is_array($kwRow)) {
            $kwId = (int) ($kwRow['id'] ?? 0);
        }
    }

    if (orange_table_exists($pdo, 'accounts')) {
        if (!orange_table_has_column($pdo, 'accounts', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE accounts ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER id'
            );
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_accounts_country_id ON accounts (country_id)'
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE accounts SET country_id = ' . (int) $kwId
                . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
        orange_catalog_safe_exec($pdo, 'ALTER TABLE accounts DROP INDEX uq_accounts_code');
        orange_catalog_safe_exec(
            $pdo,
            'CREATE UNIQUE INDEX uq_accounts_country_code ON accounts (country_id, code)'
        );
    }

    if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
        if (!orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orange_gl_account_settings ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER setting_key'
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orange_gl_account_settings SET country_id = ' . (int) $kwId
                . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orange_gl_account_settings DROP PRIMARY KEY');
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_account_settings MODIFY country_id INT UNSIGNED NOT NULL'
        );
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_account_settings ADD PRIMARY KEY (setting_key, country_id)'
        );
    }

    if (orange_table_exists($pdo, 'orange_gl_setting_alloc')) {
        if (!orange_table_has_column($pdo, 'orange_gl_setting_alloc', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orange_gl_setting_alloc ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER setting_key'
            );
        }
        if ($kwId > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orange_gl_setting_alloc SET country_id = ' . (int) $kwId
                . ' WHERE country_id IS NULL OR country_id = 0'
            );
        }
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orange_gl_setting_alloc DROP PRIMARY KEY');
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_setting_alloc MODIFY country_id INT UNSIGNED NOT NULL'
        );
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE orange_gl_setting_alloc ADD PRIMARY KEY (setting_key, country_id)'
        );
    }

    try {
        if (orange_catalog_country_gl_accounts_v49_satisfied($pdo)) {
            $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
            $ins->execute([$marker]);
        } elseif (function_exists('error_log')) {
            error_log('[orange] country_gl_accounts_v49: marker skipped — accounts.country_id still missing after migration');
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] country_gl_accounts_v49 marker: ' . $e->getMessage());
        }
    }
}

/**
 * v78 — أبعاد تحليل مردود المبيعات: مصدر الفاتورة، مرجع محفوظ، قناة تسويق، دولة.
 */
function orange_catalog_migrate_sales_returns_analytics_v78(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/sales_return_analytics.php';

    $marker = 'php_sales_returns_analytics_v78';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'sales_returns')) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    if (!orange_table_has_column($pdo, 'sales_returns', 'source_kind')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE sales_returns ADD COLUMN source_kind VARCHAR(16) NULL DEFAULT NULL AFTER type'
        );
        orange_schema_invalidate_column_check('sales_returns', 'source_kind');
    }
    if (!orange_table_has_column($pdo, 'sales_returns', 'invoice_reference')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE sales_returns ADD COLUMN invoice_reference VARCHAR(80) NULL DEFAULT NULL AFTER source_kind'
        );
        orange_schema_invalidate_column_check('sales_returns', 'invoice_reference');
    }
    if (!orange_table_has_column($pdo, 'sales_returns', 'country_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE sales_returns ADD COLUMN country_id INT UNSIGNED NULL DEFAULT NULL AFTER invoice_reference'
        );
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_sales_returns_country_id ON sales_returns (country_id)'
        );
        orange_schema_invalidate_column_check('sales_returns', 'country_id');
    }

    orange_catalog_safe_exec(
        $pdo,
        'CREATE INDEX idx_sales_returns_source_kind ON sales_returns (source_kind)'
    );
    orange_catalog_safe_exec(
        $pdo,
        'CREATE INDEX idx_sales_returns_channel_id ON sales_returns (channel_id)'
    );
    if (orange_table_has_column($pdo, 'sales_returns', 'created_at')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_sales_returns_created_at ON sales_returns (created_at)'
        );
    }

    if (orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'sales_returns', 'order_id')) {
        $hasInv = orange_table_has_column($pdo, 'orders', 'invoice_number');
        $hasOrdNum = orange_table_has_column($pdo, 'orders', 'order_number');
        $hasCh = orange_table_has_column($pdo, 'orders', 'channel_id');
        $hasOs = orange_table_has_column($pdo, 'orders', 'order_source');
        $hasOc = orange_table_has_country_id($pdo, 'orders');

        $skExpr = $hasOs
            ? "CASE
                WHEN TRIM(COALESCE(o.invoice_number, '')) LIKE 'INV-O-%' THEN 'online'
                WHEN TRIM(COALESCE(o.invoice_number, '')) LIKE 'INV-C-%' THEN 'company'
                WHEN TRIM(COALESCE(o.order_source, '')) = 'company' THEN 'company'
                ELSE 'online'
               END"
            : "'online'";
        $invExpr = $hasInv
            ? "COALESCE(NULLIF(TRIM(o.invoice_number), ''), "
            . ($hasOrdNum ? "NULLIF(TRIM(o.order_number), ''), " : '')
            . "CONCAT(IF({$skExpr} = 'company', 'INV-C', 'INV-O'), '-', o.id))"
            : ($hasOrdNum
                ? "COALESCE(NULLIF(TRIM(o.order_number), ''), CONCAT('INV-C-', o.id))"
                : "CONCAT('INV-C-', o.id)");

        $setParts = [];
        if (orange_table_has_column($pdo, 'sales_returns', 'source_kind')) {
            $setParts[] = 'sr.source_kind = ' . $skExpr;
        }
        if (orange_table_has_column($pdo, 'sales_returns', 'invoice_reference')) {
            $setParts[] = 'sr.invoice_reference = ' . $invExpr;
        }
        if ($hasCh && orange_table_has_column($pdo, 'sales_returns', 'channel_id')) {
            $setParts[] = 'sr.channel_id = COALESCE(sr.channel_id, o.channel_id)';
        }
        if ($hasOc && orange_table_has_column($pdo, 'sales_returns', 'country_id')) {
            $setParts[] = 'sr.country_id = COALESCE(sr.country_id, o.country_id)';
        }

        if ($setParts !== []) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE sales_returns sr
                 INNER JOIN orders o ON o.id = sr.order_id
                 SET ' . implode(', ', $setParts) . '
                 WHERE sr.order_id IS NOT NULL'
            );
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * عروض السلة: valid_from / valid_to إلزاميان + إيقاف تلقائي (promo_stock | gift_stock).
 */
function orange_catalog_migrate_cart_promo_schedule_v79(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/cart_promo_schedule.php';

    $marker = 'php_cart_promo_schedule_v79';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    foreach (orange_cart_promo_scheduled_tables() as $table) {
        if (!orange_table_exists($pdo, $table)) {
            continue;
        }
        if (!orange_table_has_column($pdo, $table, 'valid_from')) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE ' . $table . ' ADD COLUMN valid_from DATETIME NULL DEFAULT NULL');
            orange_schema_invalidate_column_check($table, 'valid_from');
        }
        if (!orange_table_has_column($pdo, $table, 'valid_to')) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE ' . $table . ' ADD COLUMN valid_to DATETIME NULL DEFAULT NULL');
            orange_schema_invalidate_column_check($table, 'valid_to');
        }
        if (!orange_table_has_column($pdo, $table, 'auto_paused_at')) {
            orange_catalog_safe_exec($pdo, 'ALTER TABLE ' . $table . ' ADD COLUMN auto_paused_at DATETIME NULL DEFAULT NULL');
            orange_schema_invalidate_column_check($table, 'auto_paused_at');
        }
        if (!orange_table_has_column($pdo, $table, 'auto_paused_reason')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE ' . $table . " ADD COLUMN auto_paused_reason VARCHAR(48) NULL DEFAULT NULL"
            );
            orange_schema_invalidate_column_check($table, 'auto_paused_reason');
        }
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE ' . $table . " SET valid_from = COALESCE(valid_from, CONCAT(CURDATE(), ' 00:00:00')),
             valid_to = COALESCE(valid_to, CONCAT(DATE_ADD(CURDATE(), INTERVAL 365 DAY), ' 23:59:59'))
             WHERE valid_from IS NULL OR valid_to IS NULL"
        );
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE ' . $table . ' MODIFY COLUMN valid_from DATETIME NOT NULL,
             MODIFY COLUMN valid_to DATETIME NOT NULL'
        );
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * عروض المنتج (offers): جدولة + إيقاف تلقائي عند نفاد مخزون المنتج.
 */
function orange_catalog_migrate_product_offers_schedule_v80(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_product_offers_schedule_v80';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }
    $table = 'offers';
    if (!orange_table_exists($pdo, $table)) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }
    if (!orange_table_has_column($pdo, $table, 'valid_from')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE ' . $table . ' ADD COLUMN valid_from DATETIME NULL DEFAULT NULL');
        orange_schema_invalidate_column_check($table, 'valid_from');
    }
    if (!orange_table_has_column($pdo, $table, 'valid_to')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE ' . $table . ' ADD COLUMN valid_to DATETIME NULL DEFAULT NULL');
        orange_schema_invalidate_column_check($table, 'valid_to');
    }
    if (!orange_table_has_column($pdo, $table, 'auto_paused_at')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE ' . $table . ' ADD COLUMN auto_paused_at DATETIME NULL DEFAULT NULL');
        orange_schema_invalidate_column_check($table, 'auto_paused_at');
    }
    if (!orange_table_has_column($pdo, $table, 'auto_paused_reason')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE ' . $table . " ADD COLUMN auto_paused_reason VARCHAR(48) NULL DEFAULT NULL"
        );
        orange_schema_invalidate_column_check($table, 'auto_paused_reason');
    }
    orange_catalog_safe_exec(
        $pdo,
        "UPDATE {$table} SET valid_from = COALESCE(valid_from, CONCAT(CURDATE(), ' 00:00:00')),
         valid_to = COALESCE(valid_to, CONCAT(DATE_ADD(CURDATE(), INTERVAL 365 DAY), ' 23:59:59'))
         WHERE valid_from IS NULL OR valid_to IS NULL"
    );
    orange_catalog_safe_exec(
        $pdo,
        'ALTER TABLE ' . $table . ' MODIFY COLUMN valid_from DATETIME NOT NULL,
         MODIFY COLUMN valid_to DATETIME NOT NULL'
    );

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * عروض المنتج: ترتيب عرض في تبويب العروض بالرئيسية.
 */
function orange_catalog_migrate_product_offers_sort_order_v81(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_product_offers_sort_order_v81';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }
    $table = 'offers';
    if (!orange_table_exists($pdo, $table)) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }
    if (!orange_table_has_column($pdo, $table, 'sort_order')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE ' . $table . ' ADD COLUMN sort_order INT NOT NULL DEFAULT 0');
        orange_schema_invalidate_column_check($table, 'sort_order');
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * مرحلة 9: سجل إيقاف العروض التلقائي (تدقيق).
 */
function orange_catalog_migrate_cart_promo_pause_log_v82(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_cart_promo_pause_log_v82';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'promo_pause_log')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS promo_pause_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_table VARCHAR(64) NOT NULL,
                rule_id INT UNSIGNED NOT NULL,
                reason VARCHAR(48) NOT NULL,
                country_id INT UNSIGNED NULL DEFAULT NULL,
                paused_at DATETIME NOT NULL,
                meta_json TEXT NULL,
                KEY idx_promo_pause_country_time (country_id, paused_at),
                KEY idx_promo_pause_rule (rule_table, rule_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * مرحلة 9: آخر فحص مخزون لكل قاعدة عرض (لوحة مراقبة).
 */
function orange_catalog_migrate_cart_promo_stock_check_v83(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_cart_promo_stock_check_v83';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'promo_stock_check')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS promo_stock_check (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                rule_table VARCHAR(64) NOT NULL,
                rule_id INT UNSIGNED NOT NULL,
                country_id INT UNSIGNED NULL DEFAULT NULL,
                status VARCHAR(24) NOT NULL,
                stock_reason VARCHAR(48) NULL DEFAULT NULL,
                detail_ar VARCHAR(512) NOT NULL DEFAULT \'\',
                checked_at DATETIME NOT NULL,
                UNIQUE KEY uq_promo_stock_check_rule (rule_table, rule_id, country_id),
                KEY idx_promo_stock_check_status (status, checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v89 — تقييم المخزون بـ FIFO (المرحلة م1): جداول طبقات التكلفة + بذر الرصيد الافتتاحي.
 *
 * آمن لإعادة التشغيل: marker + IF NOT EXISTS + NOT EXISTS عند البذر.
 * لا يربط COGS بعد (تشغيل ظلّي) — الربط يبدأ في م2/م3.
 * القرار + الخطة: docs/archive/ORANGE_ACCOUNTING_MAPPING_AND_REPORT_HANDOFF.txt (FIFO 2026-06-13).
 */
function orange_catalog_migrate_inventory_cost_layers_v89(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_inventory_cost_layers_v89';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'inventory_cost_layers')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS inventory_cost_layers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                country_id INT UNSIGNED NULL DEFAULT NULL,
                warehouse_id INT UNSIGNED NOT NULL,
                variant_id INT NOT NULL,
                source_type VARCHAR(24) NOT NULL,
                source_id BIGINT UNSIGNED NULL DEFAULT NULL,
                layer_date DATETIME NOT NULL,
                qty_in INT NOT NULL DEFAULT 0,
                qty_remaining INT NOT NULL DEFAULT 0,
                unit_cost DECIMAL(15,5) NOT NULL DEFAULT 0,
                note VARCHAR(191) NOT NULL DEFAULT \'\',
                created_at DATETIME NOT NULL,
                KEY idx_icl_consume (warehouse_id, variant_id, qty_remaining, layer_date, id),
                KEY idx_icl_source (source_type, source_id),
                KEY idx_icl_variant (variant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (!orange_table_exists($pdo, 'inventory_cost_consumptions')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS inventory_cost_consumptions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                layer_id BIGINT UNSIGNED NOT NULL,
                warehouse_id INT UNSIGNED NOT NULL,
                variant_id INT NOT NULL,
                consumed_qty INT NOT NULL DEFAULT 0,
                unit_cost DECIMAL(15,5) NOT NULL DEFAULT 0,
                sale_source_type VARCHAR(24) NOT NULL,
                sale_source_id BIGINT UNSIGNED NULL DEFAULT NULL,
                consumed_at DATETIME NOT NULL,
                KEY idx_icc_layer (layer_id),
                KEY idx_icc_source (sale_source_type, sale_source_id),
                KEY idx_icc_variant (warehouse_id, variant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    // بذر الرصيد الافتتاحي: طبقة واحدة لكل (مخزن، variant) لها كمية>0 ولا طبقة لها بعد.
    // تكلفة الوحدة = products.cost الحالية (أفضل تقدير متاح لحظة التحويل) — إرشادية افتتاحية فقط.
    if (
        orange_table_exists($pdo, 'inventory_cost_layers')
        && orange_table_exists($pdo, 'warehouse_variant_stock')
        && orange_table_exists($pdo, 'warehouses')
        && orange_table_exists($pdo, 'product_variants')
        && orange_table_exists($pdo, 'products')
    ) {
        $now = date('Y-m-d H:i:s');
        $seed = $pdo->prepare(
            'INSERT INTO inventory_cost_layers
                (country_id, warehouse_id, variant_id, source_type, source_id, layer_date,
                 qty_in, qty_remaining, unit_cost, note, created_at)
             SELECT w.country_id, wvs.warehouse_id, wvs.variant_id, \'opening\', NULL, ?,
                    wvs.quantity, wvs.quantity, COALESCE(p.cost, 0), \'رصيد افتتاحي\', ?
             FROM warehouse_variant_stock wvs
             INNER JOIN warehouses w ON w.id = wvs.warehouse_id
             INNER JOIN product_variants pv ON pv.id = wvs.variant_id
             INNER JOIN products p ON p.id = pv.product_id
             WHERE wvs.quantity > 0
               AND NOT EXISTS (
                   SELECT 1 FROM inventory_cost_layers icl
                   WHERE icl.warehouse_id = wvs.warehouse_id AND icl.variant_id = wvs.variant_id
               )'
        );
        try {
            $seed->execute([$now, $now]);
        } catch (Throwable $e) {
            // البذر تيسيري للمرحلة م1؛ لا نُفشل ضمان المخطط لو تعذّر (يُعاد لاحقاً).
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v90 — سياسة توصيل الدولة + OTP checkout.
 *
 * - countries: default_delivery_fee + delivery_fee_policy
 * - storefront_accounts: otp_hash + otp_expires_at + otp_sent_at + otp_attempts + otp_phone
 */
function orange_catalog_migrate_delivery_policy_checkout_otp_v90(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_delivery_policy_checkout_otp_v90';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (orange_table_exists($pdo, 'countries')) {
        if (!orange_table_has_column($pdo, 'countries', 'default_delivery_fee')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE countries ADD COLUMN default_delivery_fee DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER currency_code'
            );
            orange_schema_invalidate_column_check('countries', 'default_delivery_fee');
        }
        if (!orange_table_has_column($pdo, 'countries', 'delivery_fee_policy')) {
            orange_catalog_safe_exec(
                $pdo,
                "ALTER TABLE countries ADD COLUMN delivery_fee_policy VARCHAR(32) NOT NULL DEFAULT 'paid_all' AFTER default_delivery_fee"
            );
            orange_schema_invalidate_column_check('countries', 'delivery_fee_policy');
        }
        if (orange_table_has_column($pdo, 'countries', 'default_delivery_fee')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE countries SET default_delivery_fee = 0
                 WHERE default_delivery_fee IS NULL OR default_delivery_fee < 0'
            );
        }
        if (orange_table_has_column($pdo, 'countries', 'delivery_fee_policy')) {
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE countries
                 SET delivery_fee_policy = 'paid_all'
                 WHERE delivery_fee_policy IS NULL
                    OR TRIM(delivery_fee_policy) = ''
                    OR LOWER(TRIM(delivery_fee_policy)) NOT IN ('paid_all','free_registered','free_all')"
            );
        }
    }

    if (orange_table_exists($pdo, 'storefront_accounts')) {
        if (!orange_table_has_column($pdo, 'storefront_accounts', 'otp_hash')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE storefront_accounts ADD COLUMN otp_hash CHAR(64) NULL DEFAULT NULL AFTER verify_email_sent_at'
            );
            orange_schema_invalidate_column_check('storefront_accounts', 'otp_hash');
        }
        if (!orange_table_has_column($pdo, 'storefront_accounts', 'otp_expires_at')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE storefront_accounts ADD COLUMN otp_expires_at DATETIME NULL DEFAULT NULL AFTER otp_hash'
            );
            orange_schema_invalidate_column_check('storefront_accounts', 'otp_expires_at');
        }
        if (!orange_table_has_column($pdo, 'storefront_accounts', 'otp_sent_at')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE storefront_accounts ADD COLUMN otp_sent_at DATETIME NULL DEFAULT NULL AFTER otp_expires_at'
            );
            orange_schema_invalidate_column_check('storefront_accounts', 'otp_sent_at');
        }
        if (!orange_table_has_column($pdo, 'storefront_accounts', 'otp_attempts')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE storefront_accounts ADD COLUMN otp_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER otp_sent_at'
            );
            orange_schema_invalidate_column_check('storefront_accounts', 'otp_attempts');
        }
        if (!orange_table_has_column($pdo, 'storefront_accounts', 'otp_phone')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE storefront_accounts ADD COLUMN otp_phone VARCHAR(64) NULL DEFAULT NULL AFTER otp_attempts'
            );
            orange_schema_invalidate_column_check('storefront_accounts', 'otp_phone');
        }
        if (orange_table_has_column($pdo, 'storefront_accounts', 'otp_attempts')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE storefront_accounts SET otp_attempts = 0
                 WHERE otp_attempts IS NULL OR otp_attempts < 0'
            );
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v91 — عروض رسوم التوصيل + تفصيل رسوم/خصم التوصيل في orders + مفاتيح presets النظامية.
 */
function orange_catalog_migrate_delivery_promotions_invoice_lines_v91(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_delivery_promotions_invoice_lines_v91';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $indexExists = static function (string $table, string $indexName) use ($pdo): bool {
        $st = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1'
        );
        $st->execute([$table, $indexName]);

        return (bool) $st->fetchColumn();
    };

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS delivery_fee_promotions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            country_id INT UNSIGNED NOT NULL,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            discount_type VARCHAR(16) NOT NULL DEFAULT \'amount\',
            discount_value DECIMAL(18,4) NOT NULL DEFAULT 0,
            requires_registered_account TINYINT(1) NOT NULL DEFAULT 0,
            valid_from DATE NOT NULL,
            valid_to DATE NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_always_on TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_dfp_country_active (country_id, is_active, valid_from, valid_to, sort_order),
            KEY idx_dfp_country_sort (country_id, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('delivery_fee_promotions');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS delivery_fee_promotion_governorates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            promotion_id INT UNSIGNED NOT NULL,
            governorate_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dfpg_pair (promotion_id, governorate_id),
            KEY idx_dfpg_governorate (governorate_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('delivery_fee_promotion_governorates');

    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE IF NOT EXISTS delivery_fee_promotion_areas (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            promotion_id INT UNSIGNED NOT NULL,
            delivery_area_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dfpa_pair (promotion_id, delivery_area_id),
            KEY idx_dfpa_area (delivery_area_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    orange_schema_invalidate_table_exists('delivery_fee_promotion_areas');

    if (orange_table_exists($pdo, 'delivery_fee_promotions')) {
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN country_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'country_id');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'name_ar')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN name_ar VARCHAR(191) NOT NULL DEFAULT \'\' AFTER country_id'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'name_ar');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'name_en')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN name_en VARCHAR(191) NOT NULL DEFAULT \'\' AFTER name_ar'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'name_en');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'discount_type')) {
            orange_catalog_safe_exec(
                $pdo,
                "ALTER TABLE delivery_fee_promotions
                    ADD COLUMN discount_type VARCHAR(16) NOT NULL DEFAULT 'amount' AFTER name_en"
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'discount_type');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'discount_value')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN discount_value DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER discount_type'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'discount_value');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'requires_registered_account')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN requires_registered_account TINYINT(1) NOT NULL DEFAULT 0 AFTER discount_value'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'requires_registered_account');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'valid_from')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN valid_from DATE NOT NULL AFTER requires_registered_account'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'valid_from');
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE delivery_fee_promotions SET valid_from = CURDATE()
                 WHERE valid_from IS NULL OR valid_from = \'0000-00-00\''
            );
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'valid_to')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN valid_to DATE NOT NULL AFTER valid_from'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'valid_to');
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE delivery_fee_promotions SET valid_to = DATE_ADD(CURDATE(), INTERVAL 365 DAY)
                 WHERE valid_to IS NULL OR valid_to = \'0000-00-00\''
            );
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'sort_order')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER valid_to'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'sort_order');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'is_active')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'is_active');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'is_always_on')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN is_always_on TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'is_always_on');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'created_at')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER is_always_on'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'created_at');
        }
        if (!orange_table_has_column($pdo, 'delivery_fee_promotions', 'updated_at')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_fee_promotions
                    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at'
            );
            orange_schema_invalidate_column_check('delivery_fee_promotions', 'updated_at');
        }

        orange_catalog_safe_exec(
            $pdo,
            "UPDATE delivery_fee_promotions
             SET discount_type = 'amount'
             WHERE discount_type IS NULL
                OR TRIM(discount_type) = ''
                OR LOWER(TRIM(discount_type)) NOT IN ('amount','percent','free')"
        );
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE delivery_fee_promotions
             SET discount_value = 0
             WHERE discount_value IS NULL OR discount_value < 0'
        );
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE delivery_fee_promotions
             SET discount_value = LEAST(100, discount_value)
             WHERE LOWER(TRIM(discount_type)) = \'percent\' AND discount_value > 100'
        );
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE delivery_fee_promotions
             SET valid_to = valid_from
             WHERE valid_to < valid_from'
        );
        if (!$indexExists('delivery_fee_promotions', 'idx_dfp_country_active')) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_dfp_country_active
                 ON delivery_fee_promotions (country_id, is_active, valid_from, valid_to, sort_order)'
            );
        }
        if (!$indexExists('delivery_fee_promotions', 'idx_dfp_country_sort')) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_dfp_country_sort
                 ON delivery_fee_promotions (country_id, sort_order, id)'
            );
        }
    }

    if (orange_table_exists($pdo, 'delivery_fee_promotion_governorates')) {
        if (!$indexExists('delivery_fee_promotion_governorates', 'uq_dfpg_pair')) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE UNIQUE INDEX uq_dfpg_pair
                 ON delivery_fee_promotion_governorates (promotion_id, governorate_id)'
            );
        }
        if (!$indexExists('delivery_fee_promotion_governorates', 'idx_dfpg_governorate')) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_dfpg_governorate
                 ON delivery_fee_promotion_governorates (governorate_id)'
            );
        }
    }

    if (orange_table_exists($pdo, 'delivery_fee_promotion_areas')) {
        if (!$indexExists('delivery_fee_promotion_areas', 'uq_dfpa_pair')) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE UNIQUE INDEX uq_dfpa_pair
                 ON delivery_fee_promotion_areas (promotion_id, delivery_area_id)'
            );
        }
        if (!$indexExists('delivery_fee_promotion_areas', 'idx_dfpa_area')) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_dfpa_area
                 ON delivery_fee_promotion_areas (delivery_area_id)'
            );
        }
    }

    if (orange_table_exists($pdo, 'orders')) {
        if (!orange_table_has_column($pdo, 'orders', 'delivery_fee_base')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orders
                    ADD COLUMN delivery_fee_base DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER delivery_fee'
            );
            orange_schema_invalidate_column_check('orders', 'delivery_fee_base');
        }
        if (!orange_table_has_column($pdo, 'orders', 'delivery_fee_discount')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orders
                    ADD COLUMN delivery_fee_discount DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER delivery_fee_base'
            );
            orange_schema_invalidate_column_check('orders', 'delivery_fee_discount');
        }
        if (!orange_table_has_column($pdo, 'orders', 'delivery_promotion_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orders
                    ADD COLUMN delivery_promotion_id INT UNSIGNED NULL DEFAULT NULL AFTER delivery_fee_discount'
            );
            orange_schema_invalidate_column_check('orders', 'delivery_promotion_id');
        }
        if (
            orange_table_has_column($pdo, 'orders', 'delivery_fee')
            && orange_table_has_column($pdo, 'orders', 'delivery_fee_base')
        ) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orders
                 SET delivery_fee_base = GREATEST(0, COALESCE(delivery_fee, 0))
                 WHERE delivery_fee_base IS NULL
                    OR delivery_fee_base = 0'
            );
        }
        if (orange_table_has_column($pdo, 'orders', 'delivery_fee_base')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orders SET delivery_fee_base = 0
                 WHERE delivery_fee_base IS NULL OR delivery_fee_base < 0'
            );
        }
        if (orange_table_has_column($pdo, 'orders', 'delivery_fee_discount')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orders SET delivery_fee_discount = 0
                 WHERE delivery_fee_discount IS NULL OR delivery_fee_discount < 0'
            );
        }
        if (
            orange_table_has_column($pdo, 'orders', 'delivery_fee_base')
            && orange_table_has_column($pdo, 'orders', 'delivery_fee_discount')
        ) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orders
                 SET delivery_fee_discount = delivery_fee_base
                 WHERE delivery_fee_discount > delivery_fee_base'
            );
        }
        if (!$indexExists('orders', 'idx_orders_delivery_promotion_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE INDEX idx_orders_delivery_promotion_id ON orders (delivery_promotion_id)'
            );
        }
    }

    if (orange_table_exists($pdo, 'orange_invoice_line_presets')) {
        if (!orange_table_has_column($pdo, 'orange_invoice_line_presets', 'system_key')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE orange_invoice_line_presets
                    ADD COLUMN system_key VARCHAR(64) NULL DEFAULT NULL AFTER line_kind'
            );
            orange_schema_invalidate_column_check('orange_invoice_line_presets', 'system_key');
        }
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE orange_invoice_line_presets
             SET system_key = NULL
             WHERE TRIM(COALESCE(system_key, \'\')) = \'\''
        );
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE orange_invoice_line_presets
             SET system_key = LOWER(TRIM(system_key))
             WHERE system_key IS NOT NULL AND TRIM(system_key) <> \'\''
        );
        if (!$indexExists('orange_invoice_line_presets', 'uq_oilp_country_system_key')) {
            orange_catalog_safe_exec(
                $pdo,
                'CREATE UNIQUE INDEX uq_oilp_country_system_key
                 ON orange_invoice_line_presets (country_id, system_key)'
            );
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v92 — نمط تطبيق القيمة الأساسية للتوصيل (الكل/مخصص) على مستوى الدولة.
 */
function orange_catalog_migrate_delivery_fee_apply_mode_v92(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_delivery_fee_apply_mode_v92';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (orange_table_exists($pdo, 'countries')) {
        if (!orange_table_has_column($pdo, 'countries', 'delivery_fee_apply_mode')) {
            orange_catalog_safe_exec(
                $pdo,
                "ALTER TABLE countries
                    ADD COLUMN delivery_fee_apply_mode VARCHAR(16) NOT NULL DEFAULT 'all'
                    AFTER delivery_fee_policy"
            );
            orange_schema_invalidate_column_check('countries', 'delivery_fee_apply_mode');
        }
        if (orange_table_has_column($pdo, 'countries', 'delivery_fee_apply_mode')) {
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE countries
                 SET delivery_fee_apply_mode = LOWER(TRIM(delivery_fee_apply_mode))
                 WHERE delivery_fee_apply_mode IS NOT NULL
                   AND TRIM(delivery_fee_apply_mode) <> ''"
            );
            orange_catalog_safe_exec(
                $pdo,
                "UPDATE countries
                 SET delivery_fee_apply_mode = 'all'
                 WHERE delivery_fee_apply_mode IS NULL
                    OR TRIM(delivery_fee_apply_mode) = ''
                    OR LOWER(TRIM(delivery_fee_apply_mode)) NOT IN ('all','custom')"
            );
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v93 — حالة "بانتظار التحديد" لسعر منطقة التوصيل.
 */
function orange_catalog_migrate_delivery_fee_pending_v93(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_delivery_fee_pending_v93';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (orange_table_exists($pdo, 'delivery_areas')) {
        if (!orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee_pending')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE delivery_areas
                 ADD COLUMN delivery_fee_pending TINYINT(1) NOT NULL DEFAULT 0
                 AFTER delivery_fee'
            );
            orange_schema_invalidate_column_check('delivery_areas', 'delivery_fee_pending');
        }
        if (orange_table_has_column($pdo, 'delivery_areas', 'delivery_fee_pending')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE delivery_areas
                 SET delivery_fee_pending = 0
                 WHERE delivery_fee_pending IS NULL
                    OR delivery_fee_pending NOT IN (0, 1)'
            );
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v94 — دعم "التفعيل الدائم" لكل جداول العروض + سجل تاريخي للبداية/النهاية.
 */
function orange_catalog_migrate_promotions_always_on_v94(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_promotions_always_on_v94';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    $promoTables = [
        'cart_promotions',
        'cart_gift_promotions',
        'cart_bogo_promotions',
        'cart_combo_promotions',
        'offers',
        'delivery_fee_promotions',
    ];
    foreach ($promoTables as $table) {
        if (!orange_table_exists($pdo, $table)) {
            continue;
        }
        if (!orange_table_has_column($pdo, $table, 'is_always_on')) {
            orange_catalog_safe_exec(
                $pdo,
                'ALTER TABLE ' . $table . ' ADD COLUMN is_always_on TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active'
            );
            orange_schema_invalidate_column_check($table, 'is_always_on');
        }
        if (orange_table_has_column($pdo, $table, 'is_always_on')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE ' . $table . ' SET is_always_on = 0 WHERE is_always_on IS NULL OR is_always_on NOT IN (0,1)'
            );
        }
    }

    if (!orange_table_exists($pdo, 'promotion_always_on_history')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS promotion_always_on_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                promo_table VARCHAR(64) NOT NULL,
                promotion_id INT UNSIGNED NOT NULL,
                country_id INT UNSIGNED NULL,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                started_by_admin_id INT UNSIGNED NULL,
                ended_by_admin_id INT UNSIGNED NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_pah_table_promotion_open (promo_table, promotion_id, ended_at),
                KEY idx_pah_table_started (promo_table, started_at),
                KEY idx_pah_country_started (country_id, started_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('promotion_always_on_history');
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * ربط العروض/الولاء بالحسابات + قيود التوصيل/الولاء (v95).
 *
 * - أعمدة خصومات العروض على orders (هدية/BOGO/عرض منتج).
 * - تكلفة التوصيل التي تتحمّلها الشركة لكل منطقة (delivery_areas.company_delivery_cost).
 * - شركة التوصيل (مورّد) على مستوى المحافظة (delivery_governorates.delivery_company_id).
 * - علامة المورّد كشركة توصيل (suppliers.is_delivery_company).
 * - جداول نظام الولاء (الإعدادات لكل دولة + دفتر النقاط FIFO).
 */
function orange_catalog_migrate_offer_gl_link_v95(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_offer_gl_link_v95';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (orange_table_exists($pdo, 'orders')) {
        foreach (['cart_gift_discount', 'cart_bogo_discount', 'product_offer_discount'] as $col) {
            if (!orange_table_has_column($pdo, 'orders', $col)) {
                orange_catalog_safe_exec(
                    $pdo,
                    'ALTER TABLE orders ADD COLUMN ' . $col . ' DECIMAL(18,4) NOT NULL DEFAULT 0'
                );
                orange_schema_invalidate_column_check('orders', $col);
            }
        }
    }

    if (orange_table_exists($pdo, 'delivery_areas')
        && !orange_table_has_column($pdo, 'delivery_areas', 'company_delivery_cost')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE delivery_areas ADD COLUMN company_delivery_cost DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER delivery_fee'
        );
        orange_schema_invalidate_column_check('delivery_areas', 'company_delivery_cost');
    }

    if (orange_table_exists($pdo, 'delivery_governorates')
        && !orange_table_has_column($pdo, 'delivery_governorates', 'delivery_company_id')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE delivery_governorates ADD COLUMN delivery_company_id INT UNSIGNED NULL DEFAULT NULL'
        );
        orange_schema_invalidate_column_check('delivery_governorates', 'delivery_company_id');
        orange_catalog_safe_exec(
            $pdo,
            'CREATE INDEX idx_delivery_governorates_company ON delivery_governorates (delivery_company_id)'
        );
    }

    if (orange_table_exists($pdo, 'suppliers')
        && !orange_table_has_column($pdo, 'suppliers', 'is_delivery_company')
    ) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE suppliers ADD COLUMN is_delivery_company TINYINT(1) NOT NULL DEFAULT 0'
        );
        orange_schema_invalidate_column_check('suppliers', 'is_delivery_company');
    }

    if (!orange_table_exists($pdo, 'loyalty_settings')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS loyalty_settings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                country_id INT UNSIGNED NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                earn_rate DECIMAL(18,6) NOT NULL DEFAULT 0,
                point_value DECIMAL(18,6) NOT NULL DEFAULT 0,
                min_redeem_points INT UNSIGNED NOT NULL DEFAULT 0,
                max_redeem_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                expiry_months INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uq_loyalty_settings_country (country_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('loyalty_settings');
    }

    if (!orange_table_exists($pdo, 'loyalty_ledger')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS loyalty_ledger (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                country_id INT UNSIGNED NULL,
                customer_id INT UNSIGNED NOT NULL,
                kind VARCHAR(16) NOT NULL,
                points INT NOT NULL DEFAULT 0,
                points_remaining INT NOT NULL DEFAULT 0,
                point_value DECIMAL(18,6) NOT NULL DEFAULT 0,
                expires_at DATETIME NULL DEFAULT NULL,
                ref_type VARCHAR(32) NULL DEFAULT NULL,
                ref_id INT UNSIGNED NULL DEFAULT NULL,
                memo VARCHAR(255) NOT NULL DEFAULT \'\',
                admin_id INT UNSIGNED NULL DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_loyalty_ledger_customer (customer_id, id),
                KEY idx_loyalty_ledger_fifo (customer_id, kind, points_remaining, expires_at, id),
                KEY idx_loyalty_ledger_expiry (kind, points_remaining, expires_at),
                KEY idx_loyalty_ledger_ref (ref_type, ref_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        orange_schema_invalidate_table_exists('loyalty_ledger');
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v96 — بذرة قواعد «ربط نوع اليومية» (القسم ٢) لقيود الولاء LYE/LYX.
 *
 * تجعل اتجاه قيد الولاء مرئياً وقابلاً للتعديل من شاشة «حسابات القيود التلقائية»:
 * - LYE (كسب): مدين loyalty_program_expense / دائن loyalty_points_liability.
 * - LYX (انتهاء): مدين loyalty_points_liability / دائن loyalty_program_expense.
 *
 * لا تُدرَج إن وُجدت قاعدة سابقة لنفس نوع اليومية؛ والترحيل للدول الجديدة عبر النسخ
 * (orange_country_copy_gl_journal_type_rules_from_source). القيد نفسه له بديل آمن في
 * includes/loyalty.php إن لم تُضبط القاعدة.
 */
function orange_catalog_migrate_loyalty_journal_rules_seed_v96(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_loyalty_journal_rules_seed_v96';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (orange_table_exists($pdo, 'orange_gl_journal_type_rules')
        && orange_table_exists($pdo, 'journal_types')) {
        $rulesScoped = orange_table_has_column($pdo, 'orange_gl_journal_type_rules', 'country_id');
        $jtScoped = orange_table_has_column($pdo, 'journal_types', 'country_id');
        $seed = [
            'LYE' => ['loyalty_program_expense', 'loyalty_points_liability'],
            'LYX' => ['loyalty_points_liability', 'loyalty_program_expense'],
        ];
        try {
            $jtRows = $pdo->query(
                'SELECT id, code' . ($jtScoped ? ', country_id' : '')
                . " FROM journal_types WHERE code IN ('LYE','LYX')"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $chk = $pdo->prepare(
                "SELECT id FROM orange_gl_journal_type_rules WHERE journal_type_id = ? AND payment_terms = '' LIMIT 1"
            );
            $insScoped = $pdo->prepare(
                'INSERT INTO orange_gl_journal_type_rules
                    (country_id, journal_type_id, payment_terms, debit_setting_key, credit_setting_key)
                 VALUES (?, ?, \'\', ?, ?)'
            );
            $insPlain = $pdo->prepare(
                'INSERT INTO orange_gl_journal_type_rules
                    (journal_type_id, payment_terms, debit_setting_key, credit_setting_key)
                 VALUES (?, \'\', ?, ?)'
            );
            foreach ($jtRows as $jt) {
                $code = strtoupper(trim((string) ($jt['code'] ?? '')));
                $jtId = (int) ($jt['id'] ?? 0);
                if ($jtId <= 0 || !isset($seed[$code])) {
                    continue;
                }
                $chk->execute([$jtId]);
                if ($chk->fetchColumn() !== false) {
                    continue;
                }
                [$dk, $ck] = $seed[$code];
                if ($rulesScoped) {
                    $cid = $jtScoped ? (int) ($jt['country_id'] ?? 0) : 0;
                    $insScoped->execute([$cid > 0 ? $cid : null, $jtId, $dk, $ck]);
                } else {
                    $insPlain->execute([$jtId, $dk, $ck]);
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] loyalty journal rules seed v96: ' . $e->getMessage());
            }
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * v97 — تسوية المخزون SAJ: مفاتيح ربح/خسارة في القسم ١ + قواعد gain/loss في القسم ٢ (نمط PIN/PDN).
 *
 * - ينسخ account_id من stock_adjustment_contra القديم إلى stock_adjustment_gain و stock_adjustment_loss إن لم يُربَطا.
 * - يُدرج قاعدتَي SAJ: gain (مدين inventory / دائن stock_adjustment_gain)،
 *   loss (مدين stock_adjustment_loss / دائن inventory) إن لم توجد.
 */
function orange_catalog_migrate_stock_adjustment_gain_loss_v97(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_stock_adjustment_gain_loss_v97';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
        $scoped = orange_table_has_column($pdo, 'orange_gl_account_settings', 'country_id');
        try {
            $contraRows = $pdo->query(
                "SELECT setting_key, account_id" . ($scoped ? ', country_id' : '')
                . " FROM orange_gl_account_settings WHERE setting_key = 'stock_adjustment_contra' AND account_id > 0"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach (['stock_adjustment_gain', 'stock_adjustment_loss'] as $newKey) {
                foreach ($contraRows as $row) {
                    $accId = (int) ($row['account_id'] ?? 0);
                    if ($accId <= 0) {
                        continue;
                    }
                    if ($scoped) {
                        $cid = (int) ($row['country_id'] ?? 0);
                        $chk = $pdo->prepare(
                            'SELECT account_id FROM orange_gl_account_settings
                             WHERE setting_key = ? AND country_id = ? LIMIT 1'
                        );
                        $chk->execute([$newKey, $cid]);
                        $existing = $chk->fetch(PDO::FETCH_ASSOC);
                        if ($existing !== false && (int) ($existing['account_id'] ?? 0) > 0) {
                            continue;
                        }
                        if ($existing !== false) {
                            $pdo->prepare(
                                'UPDATE orange_gl_account_settings SET account_id = ? WHERE setting_key = ? AND country_id = ?'
                            )->execute([$accId, $newKey, $cid]);
                        } else {
                            $pdo->prepare(
                                'INSERT INTO orange_gl_account_settings (setting_key, account_id, country_id) VALUES (?, ?, ?)'
                            )->execute([$newKey, $accId, $cid]);
                        }
                    } else {
                        $chk = $pdo->prepare(
                            'SELECT account_id FROM orange_gl_account_settings WHERE setting_key = ? LIMIT 1'
                        );
                        $chk->execute([$newKey]);
                        $existing = $chk->fetch(PDO::FETCH_ASSOC);
                        if ($existing !== false && (int) ($existing['account_id'] ?? 0) > 0) {
                            continue;
                        }
                        if ($existing !== false) {
                            $pdo->prepare(
                                'UPDATE orange_gl_account_settings SET account_id = ? WHERE setting_key = ?'
                            )->execute([$accId, $newKey]);
                        } else {
                            $pdo->prepare(
                                'INSERT INTO orange_gl_account_settings (setting_key, account_id) VALUES (?, ?)'
                            )->execute([$newKey, $accId]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] stock adjustment gain/loss settings v97: ' . $e->getMessage());
            }
        }
    }

    if (orange_table_exists($pdo, 'orange_gl_journal_type_rules')
        && orange_table_exists($pdo, 'journal_types')) {
        $rulesScoped = orange_table_has_column($pdo, 'orange_gl_journal_type_rules', 'country_id');
        $jtScoped = orange_table_has_column($pdo, 'journal_types', 'country_id');
        $seed = [
            'gain' => ['inventory', 'stock_adjustment_gain'],
            'loss' => ['stock_adjustment_loss', 'inventory'],
        ];
        try {
            $jtRows = $pdo->query(
                'SELECT id' . ($jtScoped ? ', country_id' : '')
                . " FROM journal_types WHERE code = 'SAJ'"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $chk = $pdo->prepare(
                'SELECT id FROM orange_gl_journal_type_rules WHERE journal_type_id = ? AND payment_terms = ? LIMIT 1'
            );
            $insScoped = $pdo->prepare(
                'INSERT INTO orange_gl_journal_type_rules
                    (country_id, journal_type_id, payment_terms, debit_setting_key, credit_setting_key)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insPlain = $pdo->prepare(
                'INSERT INTO orange_gl_journal_type_rules
                    (journal_type_id, payment_terms, debit_setting_key, credit_setting_key)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($jtRows as $jt) {
                $jtId = (int) ($jt['id'] ?? 0);
                if ($jtId <= 0) {
                    continue;
                }
                foreach ($seed as $pt => [$dk, $ck]) {
                    $chk->execute([$jtId, $pt]);
                    if ($chk->fetchColumn() !== false) {
                        continue;
                    }
                    if ($rulesScoped) {
                        $cid = $jtScoped ? (int) ($jt['country_id'] ?? 0) : 0;
                        $insScoped->execute([$cid > 0 ? $cid : null, $jtId, $pt, $dk, $ck]);
                    } else {
                        $insPlain->execute([$jtId, $pt, $dk, $ck]);
                    }
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] stock adjustment SAJ rules seed v97: ' . $e->getMessage());
            }
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * إصلاح sort_order=0 لمناديب التوصيل (خطأ تحديث قديم) — إعادة ترقيم 1، 2، 3… لكل دولة.
 */
function orange_catalog_migrate_delivery_agents_sort_renumber_v98(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_delivery_agents_sort_renumber_v98';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    if (!orange_table_exists($pdo, 'delivery_agents')) {
        orange_catalog_schema_insert_migration_marker($pdo, $marker);

        return;
    }

    try {
        $needs = (int) ($pdo->query('SELECT COUNT(*) FROM delivery_agents WHERE sort_order <= 0')->fetchColumn() ?: 0);
        if ($needs > 0) {
            $countryIds = $pdo->query(
                'SELECT DISTINCT country_id FROM delivery_agents WHERE country_id > 0 ORDER BY country_id ASC'
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $up = $pdo->prepare('UPDATE delivery_agents SET sort_order = ? WHERE id = ? LIMIT 1');
            foreach ($countryIds as $countryId) {
                $cid = (int) $countryId;
                if ($cid <= 0) {
                    continue;
                }
                $st = $pdo->prepare(
                    'SELECT id FROM delivery_agents WHERE country_id = ? ORDER BY sort_order ASC, id ASC'
                );
                $st->execute([$cid]);
                $next = 1;
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $up->execute([$next, $id]);
                    ++$next;
                }
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] delivery_agents_sort_renumber_v98: ' . $e->getMessage());
        }
    }

    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * إعادة ترقيم id — المراحل 1–4 (علامات orange_schema_migrations؛ يُستدعى من المسار السريع أيضاً).
 */
function orange_catalog_migrate_db_id_renumber_phases(PDO $pdo): void
{
    orange_catalog_migrate_db_id_renumber_phase1_v84($pdo);
    orange_catalog_migrate_db_id_renumber_phase2_v85($pdo);
    orange_catalog_migrate_db_id_renumber_phase3_v86($pdo);
    orange_catalog_migrate_db_id_renumber_phase4_v87($pdo);
    orange_catalog_migrate_db_id_renumber_channels_v88($pdo);
}

/**
 * إعادة ترقيم id — مرحلة 1 (إعداد / واجهة / analytical_dimension / storefront_copy_lines).
 */
function orange_catalog_migrate_db_id_renumber_phase1_v84(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/db_id_renumber.php';

    $marker = 'php_db_id_renumber_phase1_v84';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    orange_db_id_renumber_run_phase1($pdo);
    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * إعادة ترقيم id — مرحلة 2 (مقاسات، أدلة استرشادية، خيارات سمات الكتالوج).
 */
function orange_catalog_migrate_db_id_renumber_phase2_v85(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/db_id_renumber.php';

    $marker = 'php_db_id_renumber_phase2_v85';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    orange_db_id_renumber_run_phase2($pdo);
    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * إعادة ترقيم id — مرحلة 3 (journal_types، قواعد GL، إعدادات نسب إن وُجد عمود id).
 */
function orange_catalog_migrate_db_id_renumber_phase3_v86(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/db_id_renumber.php';

    $marker = 'php_db_id_renumber_phase3_v86';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    orange_db_id_renumber_run_phase3($pdo);
    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * إعادة ترقيم id — مرحلة 4 (تشغيل ثقيل: منتجات، طلبات، مشتريات، مخزون، قيود، حسابات عند الحاجة).
 */
function orange_catalog_migrate_db_id_renumber_phase4_v87(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/db_id_renumber.php';

    $marker = 'php_db_id_renumber_phase4_v87';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    orange_db_id_renumber_run_phase4($pdo);
    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * إعادة ترقيم id — جدول channels (بعد حذف المكرر يدوياً من المالك).
 */
function orange_catalog_migrate_db_id_renumber_channels_v88(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    require_once __DIR__ . '/db_id_renumber.php';

    $marker = 'php_db_id_renumber_channels_v88';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    orange_db_id_renumber_run_channels($pdo);
    orange_catalog_schema_insert_migration_marker($pdo, $marker);
}

/**
 * قرار المالك 2026-06-21: فراغ أدلة المقاس الاسترشادية + إعادة AUTO_INCREMENT = 1 (مرة واحدة).
 */
function orange_catalog_migrate_advisory_sizing_clean_wipe_v99(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';

    $marker = 'php_advisory_sizing_clean_wipe_v99';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    try {
        require_once __DIR__ . '/advisory_sizing_wipe.php';
        orange_advisory_sizing_wipe_all($pdo);
        orange_catalog_schema_insert_migration_marker($pdo, $marker);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] php_advisory_sizing_clean_wipe_v99: ' . $e->getMessage());
        }
    }
}
