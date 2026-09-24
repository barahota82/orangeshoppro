<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1B.2 — Control locale policy package (revision 4).
 *
 * Dormant only: tables + DEFINER writers + verify. No Admin/Storefront wiring.
 * Authority: ORANGE_MULTIDB_PHASE21B2_PREIMPLEMENTATION_LOCALE_SEMANTICS S1–S10.
 */

/** @return list<string> */
function orange_control_locale_table_names(): array
{
    return [
        'ctrl_locales',
        'ctrl_country_locales',
    ];
}

/** @return list<string> */
function orange_control_locale_routine_names(): array
{
    return [
        'orange_ctrl_locale_global_upsert',
        'orange_ctrl_locale_country_locale_set',
        'orange_ctrl_locale_admin_locale_preference_set',
        'orange_ctrl_locale_session_locale_override_set',
    ];
}

/** @return list<string> */
function orange_control_locale_event_types(): array
{
    return [
        'locale_global_upserted',
        'locale_country_locale_set',
        'locale_admin_preference_set',
        'locale_session_override_set',
    ];
}

function orange_control_locale_column_exists(PDO $pdo, string $table, string $column): bool
{
    $schema = orange_control_current_schema_name($pdo);
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$schema, $table, $column]);

    return (int) $st->fetchColumn() === 1;
}

function orange_control_locale_fk_exists(PDO $pdo, string $table, string $constraint): bool
{
    $schema = orange_control_current_schema_name($pdo);
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $st->execute([$schema, $table, $constraint]);

    return (int) $st->fetchColumn() === 1;
}

function orange_control_locale_install_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_locales (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            locale_code VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            display_name_en VARCHAR(64) NOT NULL,
            display_name_native VARCHAR(64) NULL,
            dir VARCHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            numbering_system VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'latn',
            is_active_global TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_locales_code (locale_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_country_locales (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            country_id INT UNSIGNED NOT NULL,
            locale_code VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            is_enabled_admin TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled_storefront TINYINT(1) NOT NULL DEFAULT 0,
            is_enabled_document TINYINT(1) NOT NULL DEFAULT 0,
            is_default_admin TINYINT(1) NOT NULL DEFAULT 0,
            is_default_storefront TINYINT(1) NOT NULL DEFAULT 0,
            is_default_document TINYINT(1) NOT NULL DEFAULT 0,
            slot_default_admin INT UNSIGNED
              GENERATED ALWAYS AS (CASE WHEN is_default_admin = 1 THEN country_id ELSE NULL END) STORED,
            slot_default_storefront INT UNSIGNED
              GENERATED ALWAYS AS (CASE WHEN is_default_storefront = 1 THEN country_id ELSE NULL END) STORED,
            slot_default_document INT UNSIGNED
              GENERATED ALWAYS AS (CASE WHEN is_default_document = 1 THEN country_id ELSE NULL END) STORED,
            activated_at DATETIME(6) NULL,
            disabled_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_country_locale (country_id, locale_code),
            UNIQUE KEY uq_slot_default_admin (slot_default_admin),
            UNIQUE KEY uq_slot_default_storefront (slot_default_storefront),
            UNIQUE KEY uq_slot_default_document (slot_default_document),
            CONSTRAINT fk_ccl_country
              FOREIGN KEY (country_id) REFERENCES ctrl_countries(id)
              ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_ccl_locale
              FOREIGN KEY (locale_code) REFERENCES ctrl_locales(locale_code)
              ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** MODEL A seed: ar/en/fil/hi only. Zero ctrl_country_locales rows. */
function orange_control_locale_seed_global(PDO $pdo): void
{
    $rows = [
        ['ar', 'Arabic', 'العربية', 'rtl', 10],
        ['en', 'English', 'English', 'ltr', 20],
        ['fil', 'Filipino', 'Filipino', 'ltr', 30],
        ['hi', 'Hindi', 'हिन्दी', 'ltr', 40],
    ];
    $ins = $pdo->prepare(
        "INSERT INTO ctrl_locales (
            locale_code, display_name_en, display_name_native, dir, numbering_system,
            is_active_global, sort_order, created_at, updated_at
         ) VALUES (?, ?, ?, ?, 'latn', 1, ?, NOW(6), NOW(6))
         ON DUPLICATE KEY UPDATE
            display_name_en = VALUES(display_name_en),
            display_name_native = VALUES(display_name_native),
            dir = VALUES(dir),
            numbering_system = 'latn',
            is_active_global = 1,
            sort_order = VALUES(sort_order),
            updated_at = NOW(6)"
    );
    foreach ($rows as [$code, $en, $native, $dir, $sort]) {
        $ins->execute([$code, $en, $native, $dir, $sort]);
    }
}

/**
 * Add identity locale columns + FKs (RESTRICT → ctrl_locales).
 * Does not touch default_language / locale on ctrl_countries.
 */
function orange_control_locale_install_identity_columns(PDO $pdo): void
{
    if (!orange_control_locale_column_exists($pdo, 'ctrl_admins', 'preferred_ui_language')) {
        $pdo->exec(
            "ALTER TABLE ctrl_admins
             ADD COLUMN preferred_ui_language VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL
             AFTER is_global_access"
        );
    }
    if (!orange_control_locale_column_exists($pdo, 'ctrl_admin_sessions', 'ui_locale_override')) {
        $pdo->exec(
            "ALTER TABLE ctrl_admin_sessions
             ADD COLUMN ui_locale_override VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL
             AFTER selected_country_uuid"
        );
    }
    if (!orange_control_locale_fk_exists($pdo, 'ctrl_admins', 'fk_ctrl_admins_preferred_ui_language')) {
        $pdo->exec(
            "ALTER TABLE ctrl_admins
             ADD CONSTRAINT fk_ctrl_admins_preferred_ui_language
               FOREIGN KEY (preferred_ui_language) REFERENCES ctrl_locales(locale_code)
               ON DELETE RESTRICT ON UPDATE RESTRICT"
        );
    }
    if (!orange_control_locale_fk_exists($pdo, 'ctrl_admin_sessions', 'fk_ctrl_sessions_ui_locale_override')) {
        $pdo->exec(
            "ALTER TABLE ctrl_admin_sessions
             ADD CONSTRAINT fk_ctrl_sessions_ui_locale_override
               FOREIGN KEY (ui_locale_override) REFERENCES ctrl_locales(locale_code)
               ON DELETE RESTRICT ON UPDATE RESTRICT"
        );
    }
}

/** Existing Admin NULL prefs → ar (FINAL G1). Leaves country default_language untouched. */
function orange_control_locale_backfill_admin_preferred_ar(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE ctrl_admins SET preferred_ui_language = 'ar'
         WHERE preferred_ui_language IS NULL"
    );
}

function orange_control_locale_drop_routines(PDO $pdo): void
{
    foreach (orange_control_locale_routine_names() as $name) {
        $pdo->exec('DROP PROCEDURE IF EXISTS `' . str_replace('`', '``', $name) . '`');
    }
}

function orange_control_locale_install_routines(PDO $pdo): void
{
    orange_control_locale_drop_routines($pdo);
    $proof = orange_control_identity_sql_session_proof_select();

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_locale_global_upsert(
            IN p_locale_code VARCHAR(8),
            IN p_display_name_en VARCHAR(64),
            IN p_display_name_native VARCHAR(64),
            IN p_dir VARCHAR(3),
            IN p_is_active_global TINYINT,
            IN p_sort_order INT,
            IN p_numbering_system VARCHAR(8),
            IN p_session_token_hash CHAR(64),
            IN p_actor_kind VARCHAR(16),
            IN p_correlation_id CHAR(36),
            OUT p_locale_id BIGINT UNSIGNED
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_kind VARCHAR(16);
            DECLARE v_code VARCHAR(8);
            DECLARE v_dir VARCHAR(3);
            DECLARE v_num VARCHAR(8);
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            SET v_kind = LOWER(TRIM(IFNULL(p_actor_kind, '')));
            IF v_kind NOT IN ('human','system','fixture') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_authorization_denied';
            END IF;
            {$proof};
            IF v_actor IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_session_proof_invalid';
            END IF;
            IF v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_authorization_denied';
            END IF;
            SET v_code = LOWER(TRIM(IFNULL(p_locale_code, '')));
            IF v_code = 'tl' THEN SET v_code = 'fil'; END IF;
            IF v_code NOT REGEXP '^[a-z]{2,3}$' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_code_invalid';
            END IF;
            SET v_dir = LOWER(TRIM(IFNULL(p_dir, '')));
            IF v_dir NOT IN ('rtl','ltr') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_dir_invalid';
            END IF;
            SET v_num = LOWER(TRIM(IFNULL(p_numbering_system, '')));
            IF v_num <> 'latn' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_numbering_system_forbidden';
            END IF;
            INSERT INTO ctrl_locales (
                locale_code, display_name_en, display_name_native, dir, numbering_system,
                is_active_global, sort_order, created_at, updated_at
            ) VALUES (
                v_code, p_display_name_en, p_display_name_native, v_dir, v_num,
                IF(p_is_active_global = 1, 1, 0), IFNULL(p_sort_order, 0), NOW(6), NOW(6)
            )
            ON DUPLICATE KEY UPDATE
                display_name_en = VALUES(display_name_en),
                display_name_native = VALUES(display_name_native),
                dir = VALUES(dir),
                numbering_system = VALUES(numbering_system),
                is_active_global = VALUES(is_active_global),
                sort_order = VALUES(sort_order),
                updated_at = NOW(6);
            SELECT id INTO p_locale_id FROM ctrl_locales WHERE locale_code = v_code LIMIT 1;
            INSERT INTO ctrl_audit_events (
                event_uuid, event_type, entity_table, entity_id, actor_kind, actor_admin_id,
                correlation_id, payload_json, created_at
            ) VALUES (
                UUID(), 'locale_global_upserted', 'ctrl_locales', p_locale_id, v_kind, v_actor,
                p_correlation_id,
                JSON_OBJECT('locale_code', v_code, 'dir', v_dir, 'is_active_global', IF(p_is_active_global=1,1,0)),
                NOW(6)
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_locale_country_locale_set(
            IN p_country_id INT UNSIGNED,
            IN p_locale_code VARCHAR(8),
            IN p_is_enabled_admin TINYINT,
            IN p_is_enabled_storefront TINYINT,
            IN p_is_enabled_document TINYINT,
            IN p_is_default_admin TINYINT,
            IN p_is_default_storefront TINYINT,
            IN p_is_default_document TINYINT,
            IN p_session_token_hash CHAR(64),
            IN p_actor_kind VARCHAR(16),
            IN p_correlation_id CHAR(36),
            OUT p_country_locale_id BIGINT UNSIGNED
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_kind VARCHAR(16);
            DECLARE v_code VARCHAR(8);
            DECLARE v_en_a TINYINT; DECLARE v_en_s TINYINT; DECLARE v_en_d TINYINT;
            DECLARE v_df_a TINYINT; DECLARE v_df_s TINYINT; DECLARE v_df_d TINYINT;
            DECLARE v_active TINYINT;
            DECLARE v_cid INT UNSIGNED;
            DECLARE v_lock_id BIGINT UNSIGNED;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            SET v_kind = LOWER(TRIM(IFNULL(p_actor_kind, '')));
            IF v_kind NOT IN ('human','system','fixture') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_authorization_denied';
            END IF;
            {$proof};
            IF v_actor IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_session_proof_invalid';
            END IF;
            IF v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_authorization_denied';
            END IF;
            SET v_code = LOWER(TRIM(IFNULL(p_locale_code, '')));
            IF v_code = 'tl' THEN SET v_code = 'fil'; END IF;
            IF v_code NOT REGEXP '^[a-z]{2,3}$' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_code_invalid';
            END IF;
            SELECT id INTO v_cid FROM ctrl_countries WHERE id = p_country_id LIMIT 1 FOR UPDATE;
            IF v_cid IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_country_not_found';
            END IF;
            SELECT is_active_global INTO v_active FROM ctrl_locales WHERE locale_code = v_code LIMIT 1;
            IF v_active IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_code_invalid';
            END IF;
            SET v_df_a = IF(p_is_default_admin = 1, 1, 0);
            SET v_df_s = IF(p_is_default_storefront = 1, 1, 0);
            SET v_df_d = IF(p_is_default_document = 1, 1, 0);
            SET v_en_a = IF(p_is_enabled_admin = 1 OR v_df_a = 1, 1, 0);
            SET v_en_s = IF(p_is_enabled_storefront = 1 OR v_df_s = 1, 1, 0);
            SET v_en_d = IF(p_is_enabled_document = 1 OR v_df_d = 1, 1, 0);
            IF (v_df_a = 1 AND v_en_a <> 1) OR (v_df_s = 1 AND v_en_s <> 1) OR (v_df_d = 1 AND v_en_d <> 1) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_default_requires_enabled';
            END IF;
            IF (v_en_a = 1 OR v_en_s = 1 OR v_en_d = 1) AND v_active <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_global_inactive';
            END IF;
            SELECT id INTO v_lock_id FROM ctrl_country_locales
             WHERE country_id = p_country_id AND locale_code = v_code LIMIT 1 FOR UPDATE;
            IF v_df_a = 1 THEN
                UPDATE ctrl_country_locales SET is_default_admin = 0, updated_at = NOW(6)
                 WHERE country_id = p_country_id AND is_default_admin = 1 AND locale_code <> v_code;
            END IF;
            IF v_df_s = 1 THEN
                UPDATE ctrl_country_locales SET is_default_storefront = 0, updated_at = NOW(6)
                 WHERE country_id = p_country_id AND is_default_storefront = 1 AND locale_code <> v_code;
            END IF;
            IF v_df_d = 1 THEN
                UPDATE ctrl_country_locales SET is_default_document = 0, updated_at = NOW(6)
                 WHERE country_id = p_country_id AND is_default_document = 1 AND locale_code <> v_code;
            END IF;
            INSERT INTO ctrl_country_locales (
                country_id, locale_code,
                is_enabled_admin, is_enabled_storefront, is_enabled_document,
                is_default_admin, is_default_storefront, is_default_document,
                activated_at, disabled_at, created_at, updated_at
            ) VALUES (
                p_country_id, v_code, v_en_a, v_en_s, v_en_d, v_df_a, v_df_s, v_df_d,
                IF(v_en_a = 1 OR v_en_s = 1 OR v_en_d = 1, NOW(6), NULL),
                NULL, NOW(6), NOW(6)
            )
            ON DUPLICATE KEY UPDATE
                is_enabled_admin = VALUES(is_enabled_admin),
                is_enabled_storefront = VALUES(is_enabled_storefront),
                is_enabled_document = VALUES(is_enabled_document),
                is_default_admin = VALUES(is_default_admin),
                is_default_storefront = VALUES(is_default_storefront),
                is_default_document = VALUES(is_default_document),
                activated_at = IF(VALUES(is_enabled_admin)=1 OR VALUES(is_enabled_storefront)=1 OR VALUES(is_enabled_document)=1,
                                  IFNULL(activated_at, NOW(6)), activated_at),
                disabled_at = IF(VALUES(is_enabled_admin)=0 AND VALUES(is_enabled_storefront)=0 AND VALUES(is_enabled_document)=0,
                                 NOW(6), NULL),
                updated_at = NOW(6);
            SELECT id INTO p_country_locale_id FROM ctrl_country_locales
             WHERE country_id = p_country_id AND locale_code = v_code LIMIT 1;
            INSERT INTO ctrl_audit_events (
                event_uuid, event_type, entity_table, entity_id, actor_kind, actor_admin_id,
                correlation_id, payload_json, created_at
            ) VALUES (
                UUID(), 'locale_country_locale_set', 'ctrl_country_locales', p_country_locale_id, v_kind, v_actor,
                p_correlation_id,
                JSON_OBJECT('country_id', p_country_id, 'locale_code', v_code,
                            'is_default_admin', v_df_a, 'is_default_storefront', v_df_s, 'is_default_document', v_df_d),
                NOW(6)
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_locale_admin_locale_preference_set(
            IN p_target_admin_id INT UNSIGNED,
            IN p_preferred_ui_language VARCHAR(8),
            IN p_session_token_hash CHAR(64),
            IN p_actor_kind VARCHAR(16),
            IN p_correlation_id CHAR(36)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_kind VARCHAR(16);
            DECLARE v_code VARCHAR(8);
            DECLARE v_aid INT UNSIGNED;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            SET v_kind = LOWER(TRIM(IFNULL(p_actor_kind, '')));
            IF v_kind NOT IN ('human','system','fixture') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_authorization_denied';
            END IF;
            {$proof};
            IF v_actor IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_session_proof_invalid';
            END IF;
            SELECT id INTO v_aid FROM ctrl_admins WHERE id = p_target_admin_id LIMIT 1 FOR UPDATE;
            IF v_aid IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_admin_not_found';
            END IF;
            IF p_target_admin_id <> v_actor AND v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_authorization_denied';
            END IF;
            IF p_preferred_ui_language IS NULL OR TRIM(p_preferred_ui_language) = '' THEN
                SET v_code = NULL;
            ELSE
                SET v_code = LOWER(TRIM(p_preferred_ui_language));
                IF v_code = 'tl' THEN SET v_code = 'fil'; END IF;
                IF v_code NOT REGEXP '^[a-z]{2,3}$' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_code_invalid';
                END IF;
                IF (SELECT COUNT(*) FROM ctrl_locales WHERE locale_code = v_code) <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_unknown';
                END IF;
            END IF;
            UPDATE ctrl_admins SET preferred_ui_language = v_code, updated_at = NOW(6)
             WHERE id = p_target_admin_id;
            INSERT INTO ctrl_audit_events (
                event_uuid, event_type, entity_table, entity_id, actor_kind, actor_admin_id,
                correlation_id, payload_json, created_at
            ) VALUES (
                UUID(), 'locale_admin_preference_set', 'ctrl_admins', p_target_admin_id, v_kind, v_actor,
                p_correlation_id,
                JSON_OBJECT('target_admin_id', p_target_admin_id, 'preferred_ui_language', v_code),
                NOW(6)
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_locale_session_locale_override_set(
            IN p_target_session_token_hash CHAR(64),
            IN p_ui_locale_override VARCHAR(8),
            IN p_session_token_hash CHAR(64),
            IN p_actor_kind VARCHAR(16),
            IN p_correlation_id CHAR(36)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_kind VARCHAR(16);
            DECLARE v_code VARCHAR(8);
            DECLARE v_sid BIGINT UNSIGNED;
            DECLARE v_owner INT UNSIGNED;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            SET v_kind = LOWER(TRIM(IFNULL(p_actor_kind, '')));
            IF v_kind NOT IN ('human','system','fixture') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_authorization_denied';
            END IF;
            {$proof};
            IF v_actor IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_session_proof_invalid';
            END IF;
            SELECT id, admin_id INTO v_sid, v_owner FROM ctrl_admin_sessions
             WHERE session_token_hash = LOWER(TRIM(p_target_session_token_hash))
             LIMIT 1 FOR UPDATE;
            IF v_sid IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_session_not_found';
            END IF;
            IF v_owner <> v_actor AND v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_authorization_denied';
            END IF;
            IF p_ui_locale_override IS NULL OR TRIM(p_ui_locale_override) = '' THEN
                SET v_code = NULL;
            ELSE
                SET v_code = LOWER(TRIM(p_ui_locale_override));
                IF v_code = 'tl' THEN SET v_code = 'fil'; END IF;
                IF v_code NOT REGEXP '^[a-z]{2,3}$' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_code_invalid';
                END IF;
                IF (SELECT COUNT(*) FROM ctrl_locales WHERE locale_code = v_code) <> 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'locale_unknown';
                END IF;
            END IF;
            UPDATE ctrl_admin_sessions SET ui_locale_override = v_code WHERE id = v_sid;
            INSERT INTO ctrl_audit_events (
                event_uuid, event_type, entity_table, entity_id, actor_kind, actor_admin_id,
                correlation_id, payload_json, created_at
            ) VALUES (
                UUID(), 'locale_session_override_set', 'ctrl_admin_sessions', v_sid, v_kind, v_actor,
                p_correlation_id,
                JSON_OBJECT('session_id', v_sid, 'ui_locale_override', v_code),
                NOW(6)
            );
            COMMIT; SET v_trx = 0;
        END"
    );
}

function orange_control_locale_install_fresh(PDO $pdo): void
{
    orange_control_locale_install_tables($pdo);
    orange_control_locale_seed_global($pdo);
    orange_control_locale_install_identity_columns($pdo);
    orange_control_locale_backfill_admin_preferred_ar($pdo);
    orange_control_locale_install_routines($pdo);
}

function orange_control_locale_migrate_3to4(PDO $pdo): void
{
    // MODEL A: no auto ctrl_country_locales rows; no default_language sync.
    orange_control_locale_install_fresh($pdo);
}

/**
 * Verify completed rev4 locale package. MUST NOT CREATE / ALTER / DROP / heal.
 *
 * @throws OrangeControlTrustException schema_partial_or_incompatible
 */
function orange_control_locale_verify_rev4(PDO $pdo): void
{
    $schema = orange_control_current_schema_name($pdo);
    foreach (orange_control_locale_table_names() as $table) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'"
        );
        $st->execute([$schema, $table]);
        if ((int) $st->fetchColumn() !== 1) {
            throw new OrangeControlTrustException('schema_partial_or_incompatible');
        }
    }
    foreach (orange_control_locale_routine_names() as $routine) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ? AND ROUTINE_TYPE = 'PROCEDURE'"
        );
        $st->execute([$schema, $routine]);
        if ((int) $st->fetchColumn() !== 1) {
            throw new OrangeControlTrustException('schema_partial_or_incompatible');
        }
    }
    if (!orange_control_locale_column_exists($pdo, 'ctrl_admins', 'preferred_ui_language')
        || !orange_control_locale_column_exists($pdo, 'ctrl_admin_sessions', 'ui_locale_override')
    ) {
        throw new OrangeControlTrustException('schema_partial_or_incompatible');
    }
    if (!orange_control_locale_fk_exists($pdo, 'ctrl_admins', 'fk_ctrl_admins_preferred_ui_language')
        || !orange_control_locale_fk_exists($pdo, 'ctrl_admin_sessions', 'fk_ctrl_sessions_ui_locale_override')
        || !orange_control_locale_fk_exists($pdo, 'ctrl_country_locales', 'fk_ccl_country')
        || !orange_control_locale_fk_exists($pdo, 'ctrl_country_locales', 'fk_ccl_locale')
    ) {
        throw new OrangeControlTrustException('schema_partial_or_incompatible');
    }
    $seed = (int) $pdo->query(
        "SELECT COUNT(*) FROM ctrl_locales WHERE locale_code IN ('ar','en','fil','hi')"
    )->fetchColumn();
    if ($seed !== 4) {
        throw new OrangeControlTrustException('schema_partial_or_incompatible');
    }
}
