<?php

declare(strict_types=1);

/**
 * يُرفع عند إضافة أو تعديل خطوات ترحيل PHP داخل orange_catalog_ensure_schema() حتى تُعاد مزامنة القواعد القائمة.
 * لإجبار تشغيل الجسم الكامل يدوياً: احذف الصف من orange_catalog_schema_checkpoint أو ارفع هذا الرقم.
 *
 * @see IBRAHIM_ORANGE_MASTER.txt §2
 */
if (! defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
    define('ORANGE_CATALOG_SCHEMA_PHP_REVISION', 9);
}

/** يطابق دائماً ORANGE_CATALOG_SCHEMA_PHP_REVISION — اسم موازٍ لخطط «Schema Gate» (مرجع واحد للرقم). */
if (! defined('ORANGE_SCHEMA_CODE_VERSION')) {
    define('ORANGE_SCHEMA_CODE_VERSION', ORANGE_CATALOG_SCHEMA_PHP_REVISION);
}

/**
 * Ensures catalog tables and columns for colors, size families, colorways, and variant FKs exist.
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
    try {
        $cnt = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
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
        $cnt2 = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        if ($cnt2 > 0) {
            return;
        }
        foreach ($roots as $r) {
            $cols = ['name'];
            $vals = [$r['name']];
            $cols[] = 'code';
            $vals[] = $r['code'];
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
        require_once __DIR__ . '/schema_migrations.php';
        orange_schema_run_pending_migrations($pdo);
        /* جدول accounts قد يُفرَّغ يدوياً؛ المسار السريع كان يتخطى البذرة فلا تُعاد الجذور الافتراضية */
        orange_catalog_seed_default_accounts_if_empty($pdo);
        orange_catalog_ensure_gl_account_settings_alloc_tables($pdo);
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
        'CREATE TABLE IF NOT EXISTS size_families (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            name_en VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS size_family_sizes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            size_family_id INT NOT NULL,
            label_ar VARCHAR(191) NOT NULL DEFAULT \'\',
            label_en VARCHAR(191) NOT NULL DEFAULT \'\',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_size_family_sizes_family (size_family_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    orange_catalog_safe_exec($pdo,
        'CREATE TABLE IF NOT EXISTS product_colorways (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            primary_color_id INT NULL,
            secondary_color_id INT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_product_colorways_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'size_family_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE products ADD COLUMN size_family_id INT NULL');
    }
    if (orange_table_exists($pdo, 'products') && !orange_table_has_column($pdo, 'products', 'sizing_guide_scope')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE products ADD COLUMN sizing_guide_scope VARCHAR(16) NOT NULL DEFAULT 'none'"
        );
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
    if (orange_table_exists($pdo, 'product_variants') && !orange_table_has_column($pdo, 'product_variants', 'barcode')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE product_variants ADD COLUMN barcode VARCHAR(64) NULL');
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
    if (!orange_table_has_column($pdo, 'size_family_sizes', 'foot_length_cm')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE size_family_sizes ADD COLUMN foot_length_cm DECIMAL(6,2) NULL');
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

    if (orange_table_exists($pdo, 'categories') && !orange_table_has_column($pdo, 'categories', 'department_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE categories ADD COLUMN department_id INT NULL');
        orange_catalog_safe_exec($pdo, 'ALTER TABLE categories ADD INDEX idx_categories_department (department_id)');
    }

    /*
     |--------------------------------------------------------------------------
     | Categories / subcategories: ترقية أطوال varchar القديمة (مثلاً 100) → 191
     |--------------------------------------------------------------------------
     | يطابق scripts/mysql-create-orange-database-full.sql (تثبيت من الصفر).
     | قواعد قديمة بدون استيراد كامل: نفس أعمدة scripts/mysql-fix-drift-match-catalog-schema.sql (قسم 1).
     | MODIFY يُنفَّذ فقط عندما يكون الطول الحالي أقل من 191 (لا إعادة ALTER في كل طلب).
     */
    if (orange_table_exists($pdo, 'categories')) {
        $widenCat = static function (PDO $pdo, string $column, string $alterSql): void {
            if (!orange_table_has_column($pdo, 'categories', $column)) {
                return;
            }
            try {
                $ml = orange_schema_varchar_max_length($pdo, 'categories', $column);
                if ($ml > 0 && $ml < 191) {
                    orange_catalog_safe_exec($pdo, $alterSql);
                }
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] categories.' . $column . ' widen: ' . $e->getMessage());
                }
            }
        };
        $widenCat($pdo, 'name_en', 'ALTER TABLE categories MODIFY COLUMN name_en VARCHAR(191) NULL DEFAULT NULL');
        $widenCat($pdo, 'name_ar', 'ALTER TABLE categories MODIFY COLUMN name_ar VARCHAR(191) NULL DEFAULT NULL');
        $widenCat($pdo, 'name_fil', 'ALTER TABLE categories MODIFY COLUMN name_fil VARCHAR(191) NULL DEFAULT NULL');
        $widenCat($pdo, 'name_hi', 'ALTER TABLE categories MODIFY COLUMN name_hi VARCHAR(191) NULL DEFAULT NULL');
        $widenCat($pdo, 'slug', 'ALTER TABLE categories MODIFY COLUMN slug VARCHAR(191) NOT NULL');
    }
    if (orange_table_exists($pdo, 'subcategories')) {
        $widenSub = static function (PDO $pdo, string $column, string $alterSql): void {
            if (!orange_table_has_column($pdo, 'subcategories', $column)) {
                return;
            }
            try {
                $ml = orange_schema_varchar_max_length($pdo, 'subcategories', $column);
                if ($ml > 0 && $ml < 191) {
                    orange_catalog_safe_exec($pdo, $alterSql);
                }
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[orange] subcategories.' . $column . ' widen: ' . $e->getMessage());
                }
            }
        };
        $widenSub($pdo, 'name_ar', 'ALTER TABLE subcategories MODIFY COLUMN name_ar VARCHAR(191) NOT NULL');
        $widenSub($pdo, 'name_en', 'ALTER TABLE subcategories MODIFY COLUMN name_en VARCHAR(191) NULL DEFAULT NULL');
        $widenSub($pdo, 'name_fil', 'ALTER TABLE subcategories MODIFY COLUMN name_fil VARCHAR(191) NULL DEFAULT NULL');
        $widenSub($pdo, 'name_hi', 'ALTER TABLE subcategories MODIFY COLUMN name_hi VARCHAR(191) NULL DEFAULT NULL');
        $widenSub($pdo, 'slug', 'ALTER TABLE subcategories MODIFY COLUMN slug VARCHAR(191) NOT NULL');
    }

    static $productSubOrphansCleaned = false;
    if (
        !$productSubOrphansCleaned
        && orange_table_exists($pdo, 'subcategories')
        && orange_table_has_column($pdo, 'products', 'subcategory_id')
    ) {
        $productSubOrphansCleaned = true;
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE products p
             LEFT JOIN subcategories s ON s.id = p.subcategory_id
             SET p.subcategory_id = NULL
             WHERE p.subcategory_id IS NOT NULL AND s.id IS NULL'
        );
    }

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
            orange_journal_types_sync_canonical_defaults($pdo);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] journal_types sync: ' . $e->getMessage());
            }
        }
    }

    if (orange_table_exists($pdo, 'journal_types') && !orange_table_exists($pdo, 'orange_gl_journal_type_rules')) {
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

    if (!orange_table_exists($pdo, 'suppliers')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE suppliers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NULL,
                name VARCHAR(160) NOT NULL DEFAULT \'\',
                phone VARCHAR(40) NULL,
                notes VARCHAR(255) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_suppliers_phone (phone),
                UNIQUE KEY uq_suppliers_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
    if (orange_table_exists($pdo, 'suppliers') && !orange_table_has_column($pdo, 'suppliers', 'code')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE suppliers ADD COLUMN code VARCHAR(32) NULL');
        orange_catalog_safe_exec($pdo, 'CREATE UNIQUE INDEX uq_suppliers_code ON suppliers (code)');
    }
    if (orange_table_exists($pdo, 'suppliers') && !orange_table_has_column($pdo, 'suppliers', 'payable_account_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'ALTER TABLE suppliers ADD COLUMN payable_account_id INT NULL DEFAULT NULL AFTER notes'
        );
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
                PRIMARY KEY (admin_id, resource_key),
                KEY idx_admin_permissions_admin (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
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

    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'vat_number')) {
        orange_catalog_safe_exec(
            $pdo,
            "ALTER TABLE company_settings ADD COLUMN vat_number VARCHAR(191) NOT NULL DEFAULT ''"
        );
    }
    if (orange_table_exists($pdo, 'company_settings') && !orange_table_has_column($pdo, 'company_settings', 'invoice_footer')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE company_settings ADD COLUMN invoice_footer TEXT NULL');
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
    orange_catalog_migrate_legacy_storefront_copy_lines($pdo);

    if (!orange_table_exists($pdo, 'delivery_areas')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE IF NOT EXISTS delivery_areas (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name_ar VARCHAR(191) NOT NULL DEFAULT \'\',
                name_en VARCHAR(191) NOT NULL DEFAULT \'\',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    if (orange_table_exists($pdo, 'orders') && !orange_table_has_column($pdo, 'orders', 'delivery_area_id')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE orders ADD COLUMN delivery_area_id INT UNSIGNED NULL DEFAULT NULL');
        orange_catalog_safe_exec($pdo, 'CREATE INDEX idx_orders_delivery_area_id ON orders (delivery_area_id)');
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
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_cart_combo_active_sort (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
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
    if (orange_table_exists($pdo, 'order_items')) {
        orange_catalog_safe_exec($pdo, 'ALTER TABLE order_items MODIFY COLUMN product_id INT NULL');
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

    require_once __DIR__ . '/schema_migrations.php';
    orange_schema_run_pending_migrations($pdo);

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
 * بوابة نشر الويب: قراءة إصدار القاعدة، سلسلة ###.sql عند الحاجة، ثم النواة؛ اختياري APCu ووضع متدهور عند الفشل (إعدادات).
 */
function orange_schema_check_and_bootstrap(PDO $pdo): void
{
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
            orange_run_migrations($pdo, $dbVersion);
        } else {
            require_once __DIR__ . '/schema_migrations.php';
            orange_schema_run_pending_migrations($pdo);
            orange_catalog_ensure_schema_core($pdo);
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
    orange_schema_check_and_bootstrap($pdo);
}

/**
 * @param mixed $raw subcategory_id من الطلب (فارغ = NULL)
 * @return array{0: bool, 1: int|null, 2: string} [نجح، القيمة أو null، رسالة خطأ عربية]
 */
function orange_product_resolve_subcategory_id(PDO $pdo, int $categoryId, $raw): array
{
    if ($categoryId <= 0) {
        return [false, null, 'الفئة غير صالحة'];
    }
    $sid = ($raw === null || $raw === '') ? 0 : (int) $raw;
    if ($sid <= 0) {
        return [true, null, ''];
    }
    if (!orange_table_exists($pdo, 'subcategories')) {
        return [false, null, 'جدول الفئات الفرعية غير متوفر'];
    }
    $st = $pdo->prepare('SELECT id FROM subcategories WHERE id = ? AND category_id = ? LIMIT 1');
    $st->execute([$sid, $categoryId]);
    if (!$st->fetch()) {
        return [false, null, 'التصنيف الفرعي غير موجود أو لا يتبع الفئة المختارة'];
    }

    return [true, $sid, ''];
}

/**
 * عدد صفوف نطاق معيّن في storefront_copy_lines.
 */
function orange_catalog_count_storefront_copy_scope(PDO $pdo, string $scope): int
{
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM storefront_copy_lines WHERE scope = ?');
        $st->execute([$scope]);

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

    $defaults = orange_catalog_builtin_storefront_copy_defaults();

    try {
        if (orange_catalog_count_storefront_copy_scope($pdo, 'home_hero') === 0) {
            $ins = $pdo->prepare(
                'INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                 VALUES (?, ?, 1, ?, ?, ?, ?)'
            );
            foreach ($defaults['home_hero'] as $idx => $line) {
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

        if (orange_catalog_count_storefront_copy_scope($pdo, 'header_tagline') === 0) {
            $t = $defaults['header_tagline'];
            $ins = $pdo->prepare(
                'INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                 VALUES (?, ?, 1, ?, ?, ?, ?)'
            );
            $ins->execute(['header_tagline', 1, $t['ar'], $t['en'], $t['fil'], $t['hi']]);
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] seed storefront_copy_lines: ' . $e->getMessage());
        }
    }
}

/**
 * ترحيل من storefront_home_hero إلى storefront_copy_lines — لكل نطاق على حدة (لا يتوقف إن وُجدت صفوف لنطاق آخر).
 */
function orange_catalog_migrate_legacy_storefront_copy_lines(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'storefront_copy_lines')) {
        return;
    }

    try {
        if (orange_catalog_count_storefront_copy_scope($pdo, 'home_hero') === 0
            && orange_table_exists($pdo, 'storefront_home_hero')) {
            $row = $pdo->query('SELECT * FROM storefront_home_hero WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $ins = $pdo->prepare(
                    'INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                     VALUES (?, ?, 1, ?, ?, ?, ?)'
                );
                for ($i = 1; $i <= 3; ++$i) {
                    $ar = trim((string) ($row['line_' . $i . '_ar'] ?? ''));
                    $en = trim((string) ($row['line_' . $i . '_en'] ?? ''));
                    $fil = trim((string) ($row['line_' . $i . '_fil'] ?? ''));
                    $hi = trim((string) ($row['line_' . $i . '_hi'] ?? ''));
                    if ($ar === '' && $en === '' && $fil === '' && $hi === '') {
                        continue;
                    }
                    $ins->execute(['home_hero', $i, $ar, $en, $fil, $hi]);
                }
            }
        }

        if (orange_catalog_count_storefront_copy_scope($pdo, 'header_tagline') === 0
            && orange_table_exists($pdo, 'storefront_home_hero')) {
            $row = $pdo->query('SELECT * FROM storefront_home_hero WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $har = trim((string) ($row['header_tagline_ar'] ?? ''));
                $hen = trim((string) ($row['header_tagline_en'] ?? ''));
                $hfil = trim((string) ($row['header_tagline_fil'] ?? ''));
                $hhi = trim((string) ($row['header_tagline_hi'] ?? ''));
                if ($har !== '' || $hen !== '' || $hfil !== '' || $hhi !== '') {
                    $ins = $pdo->prepare(
                        'INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi)
                         VALUES (?, ?, 1, ?, ?, ?, ?)'
                    );
                    $ins->execute(['header_tagline', 1, $har, $hen, $hfil, $hhi]);
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
