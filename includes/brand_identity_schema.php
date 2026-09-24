<?php

declare(strict_types=1);

/**
 * M03 Step 3 — dormant Brand Identity schema contract (persistent).
 *
 * NOT executed against live Control / Country MySQL by this candidate.
 * NOT wired into live includes/control_schema.php.
 * Does NOT bump ORANGE_CONTROL_SCHEMA_REVISION (accepted live = 5).
 * Does NOT bump Country schema 124.
 * Does NOT reuse M02 Domain tables.
 *
 * Future apply binding: Control revision 6 (see control_rev6_brand_identity_migrate.php).
 * Live Control revision must remain 5 until a later Owner-authorized apply.
 */

const ORANGE_BRAND_IDENTITY_SCHEMA_CONTRACT = 'M03_STEP3_PERSISTENT_SEVEN_TABLES_V1';
const ORANGE_BRAND_IDENTITY_FUTURE_CONTROL_REVISION_BINDING = 6;
const ORANGE_BRAND_IDENTITY_LIVE_CONTROL_REVISION_MUST_REMAIN = 5;
const ORANGE_BRAND_IDENTITY_LIVE_COUNTRY_SCHEMA_MUST_REMAIN = 124;

/**
 * @return list<string>
 */
function orange_brand_identity_schema_table_names(): array
{
    return [
        'orange_brand_identity_versions',
        'orange_brand_identity_translations',
        'orange_brand_asset_objects',
        'orange_brand_slot_versions',
        'orange_brand_releases',
        'orange_brand_release_slots',
        'orange_brand_identity_audit_events',
    ];
}

/**
 * Dedicated audit table is required: Control ctrl_audit_events PHP writer
 * (orange_control_insert_audit) accepts telemetry types only; identity events
 * live in a different closed vocabulary; trust events are reserved.
 *
 * @return list<string>
 */
function orange_brand_identity_audit_event_types(): array
{
    return [
        'brand_identity.object.ingest',
        'brand_identity.identity.create',
        'brand_identity.identity.transition',
        'brand_identity.identity.change',
        'brand_identity.slot.create',
        'brand_identity.slot.transition',
        'brand_identity.release.create',
        'brand_identity.release.transition',
        'brand_identity.release.activate',
        'brand_identity.release.rollback_full',
        'brand_identity.release.rollback_slot',
    ];
}

/**
 * @return array<string, string> table => CREATE TABLE IF NOT EXISTS (MySQL/MariaDB)
 */
function orange_brand_identity_schema_ddl(): array
{
    return [
        'orange_brand_identity_versions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS orange_brand_identity_versions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  state VARCHAR(32) NOT NULL,
  default_locale VARCHAR(16) NOT NULL,
  change_note VARCHAR(500) NULL,
  created_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  previewed_at DATETIME NULL,
  activated_at DATETIME NULL,
  archived_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_obiv_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'orange_brand_identity_translations' => <<<'SQL'
CREATE TABLE IF NOT EXISTS orange_brand_identity_translations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identity_version_id INT UNSIGNED NOT NULL,
  locale VARCHAR(16) NOT NULL,
  text_key VARCHAR(64) NOT NULL,
  text_value TEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_obit_version_locale_key (identity_version_id, locale, text_key),
  KEY idx_obit_version (identity_version_id),
  CONSTRAINT orange_fk_obit_identity
    FOREIGN KEY (identity_version_id) REFERENCES orange_brand_identity_versions (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'orange_brand_asset_objects' => <<<'SQL'
CREATE TABLE IF NOT EXISTS orange_brand_asset_objects (
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mime VARCHAR(64) NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  width INT UNSIGNED NOT NULL,
  height INT UNSIGNED NOT NULL,
  aspect_ratio DECIMAL(10,4) NOT NULL,
  alpha_flag TINYINT(1) NOT NULL DEFAULT 0,
  alpha_note VARCHAR(64) NOT NULL DEFAULT '',
  relpath VARCHAR(255) NOT NULL,
  original_filename VARCHAR(191) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'orange_brand_slot_versions' => <<<'SQL'
CREATE TABLE IF NOT EXISTS orange_brand_slot_versions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slot_code VARCHAR(64) NOT NULL,
  original_object_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  derivative_object_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  state VARCHAR(32) NOT NULL,
  change_note VARCHAR(500) NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  previewed_at DATETIME NULL,
  activated_at DATETIME NULL,
  archived_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_obsv_slot_state (slot_code, state),
  KEY idx_obsv_original (original_object_id),
  KEY idx_obsv_derivative (derivative_object_id),
  CONSTRAINT orange_fk_obsv_original
    FOREIGN KEY (original_object_id) REFERENCES orange_brand_asset_objects (sha256)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT orange_fk_obsv_derivative
    FOREIGN KEY (derivative_object_id) REFERENCES orange_brand_asset_objects (sha256)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'orange_brand_releases' => <<<'SQL'
CREATE TABLE IF NOT EXISTS orange_brand_releases (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identity_version_id INT UNSIGNED NOT NULL,
  state VARCHAR(32) NOT NULL,
  note VARCHAR(500) NULL,
  current_flag TINYINT(1) NULL,
  created_by INT UNSIGNED NOT NULL,
  approved_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  activated_at DATETIME NULL,
  archived_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_obr_current_flag (current_flag),
  KEY idx_obr_state (state),
  KEY idx_obr_identity (identity_version_id),
  CONSTRAINT orange_fk_obr_identity
    FOREIGN KEY (identity_version_id) REFERENCES orange_brand_identity_versions (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'orange_brand_release_slots' => <<<'SQL'
CREATE TABLE IF NOT EXISTS orange_brand_release_slots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  release_id INT UNSIGNED NOT NULL,
  slot_code VARCHAR(64) NOT NULL,
  slot_version_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_obrs_release_slot (release_id, slot_code),
  KEY idx_obrs_version (slot_version_id),
  CONSTRAINT orange_fk_obrs_release
    FOREIGN KEY (release_id) REFERENCES orange_brand_releases (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT orange_fk_obrs_slot_version
    FOREIGN KEY (slot_version_id) REFERENCES orange_brand_slot_versions (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        'orange_brand_identity_audit_events' => <<<'SQL'
CREATE TABLE IF NOT EXISTS orange_brand_identity_audit_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  actor_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  entity_table VARCHAR(64) NOT NULL DEFAULT '',
  entity_id VARCHAR(64) NOT NULL DEFAULT '',
  release_id INT UNSIGNED NULL,
  slot_code VARCHAR(64) NULL,
  identity_version_id INT UNSIGNED NULL,
  slot_version_id INT UNSIGNED NULL,
  before_id VARCHAR(64) NULL,
  after_id VARCHAR(64) NULL,
  change_note VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_obia_created (created_at),
  KEY idx_obia_type (event_type),
  KEY idx_obia_release (release_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];
}

/**
 * @return list<array{from:string,column:string,to:string,on_delete:string,on_update:string}>
 */
function orange_brand_identity_schema_fk_matrix(): array
{
    return [
        [
            'from' => 'orange_brand_identity_translations',
            'column' => 'identity_version_id',
            'to' => 'orange_brand_identity_versions.id',
            'on_delete' => 'RESTRICT',
            'on_update' => 'RESTRICT',
        ],
        [
            'from' => 'orange_brand_slot_versions',
            'column' => 'original_object_id',
            'to' => 'orange_brand_asset_objects.sha256',
            'on_delete' => 'RESTRICT',
            'on_update' => 'RESTRICT',
        ],
        [
            'from' => 'orange_brand_slot_versions',
            'column' => 'derivative_object_id',
            'to' => 'orange_brand_asset_objects.sha256',
            'on_delete' => 'RESTRICT',
            'on_update' => 'RESTRICT',
        ],
        [
            'from' => 'orange_brand_releases',
            'column' => 'identity_version_id',
            'to' => 'orange_brand_identity_versions.id',
            'on_delete' => 'RESTRICT',
            'on_update' => 'RESTRICT',
        ],
        [
            'from' => 'orange_brand_release_slots',
            'column' => 'release_id',
            'to' => 'orange_brand_releases.id',
            'on_delete' => 'RESTRICT',
            'on_update' => 'RESTRICT',
        ],
        [
            'from' => 'orange_brand_release_slots',
            'column' => 'slot_version_id',
            'to' => 'orange_brand_slot_versions.id',
            'on_delete' => 'RESTRICT',
            'on_update' => 'RESTRICT',
        ],
    ];
}

/**
 * @return array<string, list<string>>
 */
function orange_brand_identity_schema_required_columns(): array
{
    return [
        'orange_brand_identity_versions' => [
            'id', 'state', 'default_locale', 'change_note', 'created_by', 'approved_by',
            'created_at', 'previewed_at', 'activated_at', 'archived_at',
        ],
        'orange_brand_identity_translations' => [
            'id', 'identity_version_id', 'locale', 'text_key', 'text_value',
        ],
        'orange_brand_asset_objects' => [
            'sha256', 'mime', 'byte_size', 'width', 'height', 'aspect_ratio',
            'alpha_flag', 'alpha_note', 'relpath', 'original_filename', 'created_by', 'created_at',
        ],
        'orange_brand_slot_versions' => [
            'id', 'slot_code', 'original_object_id', 'derivative_object_id', 'state', 'change_note',
            'uploaded_by', 'approved_by', 'created_at', 'previewed_at', 'activated_at', 'archived_at',
        ],
        'orange_brand_releases' => [
            'id', 'identity_version_id', 'state', 'note', 'current_flag', 'created_by',
            'approved_by', 'created_at', 'activated_at', 'archived_at',
        ],
        'orange_brand_release_slots' => [
            'id', 'release_id', 'slot_code', 'slot_version_id',
        ],
        'orange_brand_identity_audit_events' => [
            'id', 'event_type', 'actor_id', 'created_at', 'entity_table', 'entity_id',
            'release_id', 'slot_code', 'identity_version_id', 'slot_version_id',
            'before_id', 'after_id', 'change_note',
        ],
    ];
}

/**
 * @return list<array{table:string,columns:list<string>}>
 */
function orange_brand_identity_schema_required_uniques(): array
{
    return [
        ['table' => 'orange_brand_identity_translations', 'columns' => ['identity_version_id', 'locale', 'text_key']],
        ['table' => 'orange_brand_release_slots', 'columns' => ['release_id', 'slot_code']],
        ['table' => 'orange_brand_releases', 'columns' => ['current_flag']],
    ];
}

/**
 * Future / disposable-only MySQL install. Do not call against live Control in M03.
 */
function orange_brand_identity_install_tables(PDO $pdo): void
{
    foreach (orange_brand_identity_schema_ddl() as $sql) {
        $pdo->exec($sql);
    }
}

function orange_brand_identity_schema_verify_failed(): void
{
    throw new RuntimeException('BRAND_IDENTITY_SCHEMA_VERIFY_FAILED');
}

function orange_brand_identity_restrict_ok(string $rule): bool
{
    $r = strtoupper(trim($rule));

    return $r === 'RESTRICT' || $r === 'NO ACTION' || $r === '';
}

function orange_brand_identity_sha_type_ok(string $dataType, ?int $maxLen): bool
{
    $t = strtolower(trim($dataType));
    if (str_starts_with($t, 'char') || str_starts_with($t, 'varchar') || str_starts_with($t, 'binary') || str_starts_with($t, 'varbinary')) {
        return $maxLen === null || $maxLen >= 64;
    }

    return str_starts_with($t, 'text') || str_starts_with($t, 'blob') || $t === 'tinytext' || $t === 'mediumtext' || $t === 'longtext';
}

function orange_brand_identity_verify_schema(PDO $pdo): void
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        orange_brand_identity_verify_schema_sqlite($pdo);

        return;
    }
    orange_brand_identity_verify_schema_mysql($pdo);
}

function orange_brand_identity_verify_schema_mysql(PDO $pdo): void
{
    $schema = $pdo->query('SELECT DATABASE()');
    $db = $schema !== false ? (string) $schema->fetchColumn() : '';
    if ($db === '') {
        orange_brand_identity_schema_verify_failed();
    }
    $tables = [];
    $st = $pdo->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?'
    );
    $st->execute([$db, 'BASE TABLE']);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $tables[strtolower((string) $row['TABLE_NAME'])] = true;
    }
    foreach (orange_brand_identity_schema_table_names() as $table) {
        if (!isset($tables[strtolower($table)])) {
            orange_brand_identity_schema_verify_failed();
        }
    }
    $colStmt = $pdo->prepare(
        'SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    foreach (orange_brand_identity_schema_required_columns() as $table => $need) {
        $colStmt->execute([$db, $table]);
        $have = [];
        while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $name = strtolower((string) $c['COLUMN_NAME']);
            $have[$name] = $c;
        }
        foreach ($need as $col) {
            if (!isset($have[strtolower($col)])) {
                orange_brand_identity_schema_verify_failed();
            }
        }
        if (!isset($have['current_flag']) && $table === 'orange_brand_releases') {
            orange_brand_identity_schema_verify_failed();
        }
        if ($table === 'orange_brand_releases') {
            if (strtoupper((string) $have['current_flag']['IS_NULLABLE']) !== 'YES') {
                orange_brand_identity_schema_verify_failed();
            }
        }
        if ($table === 'orange_brand_asset_objects') {
            $max = $have['sha256']['CHARACTER_MAXIMUM_LENGTH'];
            if (!orange_brand_identity_sha_type_ok((string) $have['sha256']['DATA_TYPE'], $max === null ? null : (int) $max)) {
                orange_brand_identity_schema_verify_failed();
            }
        }
        if ($table === 'orange_brand_slot_versions') {
            foreach (['original_object_id', 'derivative_object_id'] as $fkCol) {
                $max = $have[$fkCol]['CHARACTER_MAXIMUM_LENGTH'];
                if (!orange_brand_identity_sha_type_ok((string) $have[$fkCol]['DATA_TYPE'], $max === null ? null : (int) $max)) {
                    orange_brand_identity_schema_verify_failed();
                }
            }
        }
    }
    $idxStmt = $pdo->prepare(
        'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
         ORDER BY INDEX_NAME, SEQ_IN_INDEX'
    );
    foreach (orange_brand_identity_schema_required_uniques() as $uq) {
        $idxStmt->execute([$db, $uq['table']]);
        $byIndex = [];
        while ($r = $idxStmt->fetch(PDO::FETCH_ASSOC)) {
            if ((int) $r['NON_UNIQUE'] !== 0) {
                continue;
            }
            $iname = (string) $r['INDEX_NAME'];
            $byIndex[$iname][] = strtolower((string) $r['COLUMN_NAME']);
        }
        $want = array_map('strtolower', $uq['columns']);
        $ok = false;
        foreach ($byIndex as $cols) {
            if ($cols === $want) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            orange_brand_identity_schema_verify_failed();
        }
    }
    $fkStmt = $pdo->prepare(
        'SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                rc.DELETE_RULE, rc.UPDATE_RULE
         FROM information_schema.KEY_COLUMN_USAGE kcu
         INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
           ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
          AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
          AND rc.TABLE_NAME = kcu.TABLE_NAME
         WHERE kcu.TABLE_SCHEMA = ? AND kcu.REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $fkStmt->execute([$db]);
    $fks = [];
    while ($r = $fkStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = strtolower((string) $r['TABLE_NAME'] . '.' . (string) $r['COLUMN_NAME']);
        $fks[$key] = $r;
    }
    foreach (orange_brand_identity_schema_fk_matrix() as $need) {
        $key = strtolower($need['from'] . '.' . $need['column']);
        if (!isset($fks[$key])) {
            orange_brand_identity_schema_verify_failed();
        }
        $got = $fks[$key];
        $to = explode('.', $need['to'], 2);
        if (strtolower((string) $got['REFERENCED_TABLE_NAME']) !== strtolower($to[0])
            || strtolower((string) $got['REFERENCED_COLUMN_NAME']) !== strtolower($to[1])) {
            orange_brand_identity_schema_verify_failed();
        }
        if (!orange_brand_identity_restrict_ok((string) $got['DELETE_RULE'])
            || !orange_brand_identity_restrict_ok((string) $got['UPDATE_RULE'])) {
            orange_brand_identity_schema_verify_failed();
        }
    }
}

function orange_brand_identity_verify_schema_sqlite(PDO $pdo): void
{
    $tables = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tables[strtolower((string) $row['name'])] = true;
    }
    foreach (orange_brand_identity_schema_table_names() as $table) {
        if (!isset($tables[strtolower($table)])) {
            orange_brand_identity_schema_verify_failed();
        }
    }
    foreach (orange_brand_identity_schema_required_columns() as $table => $need) {
        $info = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
        $have = [];
        foreach ($info as $c) {
            $have[strtolower((string) $c['name'])] = $c;
        }
        foreach ($need as $col) {
            if (!isset($have[strtolower($col)])) {
                orange_brand_identity_schema_verify_failed();
            }
        }
        if ($table === 'orange_brand_releases') {
            if ((int) $have['current_flag']['notnull'] !== 0) {
                orange_brand_identity_schema_verify_failed();
            }
        }
        if ($table === 'orange_brand_asset_objects') {
            if (!orange_brand_identity_sha_type_ok((string) $have['sha256']['type'], null)) {
                orange_brand_identity_schema_verify_failed();
            }
        }
        if ($table === 'orange_brand_slot_versions') {
            foreach (['original_object_id', 'derivative_object_id'] as $fkCol) {
                if (!orange_brand_identity_sha_type_ok((string) $have[$fkCol]['type'], null)) {
                    orange_brand_identity_schema_verify_failed();
                }
            }
        }
    }
    foreach (orange_brand_identity_schema_required_uniques() as $uq) {
        $ok = false;
        $idxList = $pdo->query('PRAGMA index_list(' . $uq['table'] . ')')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($idxList as $idx) {
            if ((int) $idx['unique'] !== 1) {
                continue;
            }
            $cols = [];
            foreach ($pdo->query('PRAGMA index_info(' . $idx['name'] . ')')->fetchAll(PDO::FETCH_ASSOC) as $ic) {
                $cols[] = strtolower((string) $ic['name']);
            }
            if ($cols === array_map('strtolower', $uq['columns'])) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            orange_brand_identity_schema_verify_failed();
        }
    }
    $fkHave = [];
    foreach (['orange_brand_identity_translations', 'orange_brand_slot_versions', 'orange_brand_releases', 'orange_brand_release_slots'] as $table) {
        foreach ($pdo->query('PRAGMA foreign_key_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $fk) {
            $key = strtolower($table . '.' . (string) $fk['from']);
            $fkHave[$key] = $fk;
        }
    }
    foreach (orange_brand_identity_schema_fk_matrix() as $need) {
        $key = strtolower($need['from'] . '.' . $need['column']);
        if (!isset($fkHave[$key])) {
            orange_brand_identity_schema_verify_failed();
        }
        $got = $fkHave[$key];
        $to = explode('.', $need['to'], 2);
        if (strtolower((string) $got['table']) !== strtolower($to[0])
            || strtolower((string) $got['to']) !== strtolower($to[1])) {
            orange_brand_identity_schema_verify_failed();
        }
        if (!orange_brand_identity_restrict_ok((string) $got['on_delete'])
            || !orange_brand_identity_restrict_ok((string) $got['on_update'])) {
            orange_brand_identity_schema_verify_failed();
        }
    }
}
