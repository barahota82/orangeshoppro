<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * GAP-ACC-14 — إقفال التعديلات: سجل موحّد + صلاحيات can_lock / can_unlock.
 */
function orange_catalog_ensure_edit_lock_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!orange_table_exists($pdo, 'orange_edit_lock_registry')) {
        orange_catalog_safe_exec(
            $pdo,
            'CREATE TABLE orange_edit_lock_registry (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                doc_kind VARCHAR(64) NOT NULL,
                entity_id INT NOT NULL,
                country_id INT NULL DEFAULT NULL,
                reference VARCHAR(128) NOT NULL DEFAULT \'\',
                label_ar VARCHAR(256) NOT NULL DEFAULT \'\',
                amount DECIMAL(18,4) NULL DEFAULT NULL,
                saved_at DATETIME NOT NULL,
                journal_voucher_id INT NULL DEFAULT NULL,
                is_locked TINYINT(1) NOT NULL DEFAULT 0,
                locked_at DATETIME NULL DEFAULT NULL,
                locked_by_admin_id INT NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_edit_lock_doc (doc_kind, entity_id, country_id),
                KEY idx_edit_lock_saved (saved_at),
                KEY idx_edit_lock_locked (is_locked, saved_at),
                KEY idx_edit_lock_kind (doc_kind, saved_at)
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

    $done = true;
}
