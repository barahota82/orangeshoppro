<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1B.1 — Control Central Identity (dormant).
 * Rev4 (+2.1B.2): preferred_ui_language / ui_locale_override columns in CREATE;
 * FKs to ctrl_locales installed by control_locale_schema.php.
 *
 * Invoked only from orange_control_ensure_schema(). No Production/login wiring.
 * Owner contracts: readiness + final internal + preimplementation security + §6 clarifications.
 * session_issue semantics unchanged by locale (override via orange_ctrl_locale_session_locale_override_set).
 */

/** @return list<string> */
function orange_control_identity_table_names(): array
{
    return [
        'ctrl_admins',
        'ctrl_admin_sessions',
        'ctrl_admin_permissions',
        'ctrl_admin_country_access',
        'ctrl_admin_login_throttle',
        'ctrl_identity_events',
    ];
}

/** Externally callable identity routines (emit_event excluded — nested only). */
function orange_control_identity_callable_routine_names(): array
{
    return [
        'orange_ctrl_identity_admin_upsert',
        'orange_ctrl_identity_set_superuser',
        'orange_ctrl_identity_set_global_access',
        'orange_ctrl_identity_set_password',
        'orange_ctrl_identity_permissions_replace',
        'orange_ctrl_identity_country_access_set',
        'orange_ctrl_identity_session_issue',
        'orange_ctrl_identity_session_revoke',
        'orange_ctrl_identity_login_throttle_apply',
    ];
}

/** All identity routines including internal emit_event. */
function orange_control_identity_all_routine_names(): array
{
    return array_merge(
        orange_control_identity_callable_routine_names(),
        ['orange_ctrl_identity_emit_event']
    );
}

/** Closed identity event_type vocabulary (no admin_hard_deleted). */
function orange_control_identity_event_types(): array
{
    return [
        'admin_created',
        'admin_updated',
        'admin_disabled',
        'admin_enabled',
        'password_changed',
        'superuser_granted',
        'superuser_revoked',
        'global_access_granted',
        'global_access_revoked',
        'permissions_replaced',
        'country_access_changed',
        'session_issued',
        'session_revoked',
        'login_throttle_cleared',
    ];
}

function orange_control_identity_install_tables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_admins (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            username VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_superuser TINYINT(1) NOT NULL DEFAULT 0,
            is_global_access TINYINT(1) NOT NULL DEFAULT 0,
            preferred_ui_language VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
            disabled_at DATETIME(6) NULL,
            password_changed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            row_version INT NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_admins_uuid (admin_uuid),
            UNIQUE KEY uq_ctrl_admins_username (username),
            KEY idx_ctrl_admins_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_admin_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id INT UNSIGNED NOT NULL,
            session_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            token_fingerprint CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
            created_at DATETIME(6) NOT NULL,
            expires_at DATETIME(6) NOT NULL,
            last_seen_at DATETIME(6) NULL,
            idle_timeout_sec INT NOT NULL DEFAULT 43200,
            selected_country_id INT UNSIGNED NULL,
            selected_country_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ui_locale_override VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
            ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            user_agent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            revoked_at DATETIME(6) NULL,
            revoke_reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_session_token_hash (session_token_hash),
            KEY idx_ctrl_session_admin (admin_id),
            KEY idx_ctrl_session_expires (expires_at),
            CONSTRAINT fk_ctrl_session_admin
              FOREIGN KEY (admin_id) REFERENCES ctrl_admins(id)
              ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_ctrl_session_country
              FOREIGN KEY (selected_country_id) REFERENCES ctrl_countries(id)
              ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_admin_permissions (
            admin_id INT UNSIGNED NOT NULL,
            resource_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            can_view TINYINT(1) NOT NULL DEFAULT 0,
            can_edit TINYINT(1) NOT NULL DEFAULT 0,
            can_delete TINYINT(1) NOT NULL DEFAULT 0,
            can_lock TINYINT(1) NOT NULL DEFAULT 0,
            can_unlock TINYINT(1) NOT NULL DEFAULT 0,
            can_print TINYINT(1) NOT NULL DEFAULT 0,
            can_export TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (admin_id, resource_key),
            KEY idx_ctrl_admin_perm_admin (admin_id),
            CONSTRAINT fk_ctrl_perm_admin
              FOREIGN KEY (admin_id) REFERENCES ctrl_admins(id)
              ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_admin_country_access (
            admin_id INT UNSIGNED NOT NULL,
            country_id INT UNSIGNED NOT NULL,
            can_access TINYINT(1) NOT NULL DEFAULT 1,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            default_admin_slot INT UNSIGNED
              GENERATED ALWAYS AS (IF(is_default = 1, admin_id, NULL)) STORED,
            PRIMARY KEY (admin_id, country_id),
            UNIQUE KEY uq_ctrl_admin_default_country (default_admin_slot),
            CONSTRAINT fk_ctrl_access_admin
              FOREIGN KEY (admin_id) REFERENCES ctrl_admins(id)
              ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_ctrl_access_country
              FOREIGN KEY (country_id) REFERENCES ctrl_countries(id)
              ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_admin_login_throttle (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            scope_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            failed_count INT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME(6) NOT NULL,
            locked_until DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_admin_throttle_scope (scope_type, scope_key),
            KEY idx_ctrl_admin_throttle_locked (locked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_identity_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            correlation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            target_admin_id INT UNSIGNED NULL,
            target_admin_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
            human_actor_admin_id INT UNSIGNED NULL,
            human_actor_admin_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
            invoker_mysql_user VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            definer_mysql_user VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            actor_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload_json JSON NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_identity_event_uuid (event_uuid),
            KEY idx_ctrl_identity_events_created (created_at),
            KEY idx_ctrl_identity_events_admin (target_admin_id),
            KEY idx_ctrl_identity_events_type (event_type),
            CONSTRAINT fk_ctrl_identity_events_target
              FOREIGN KEY (target_admin_id) REFERENCES ctrl_admins(id)
              ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT fk_ctrl_identity_events_human
              FOREIGN KEY (human_actor_admin_id) REFERENCES ctrl_admins(id)
              ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * Fail-closed orphan gate before Trust actor FKs (Owner §6.6).
 */
function orange_control_identity_orphan_gate(PDO $pdo): void
{
    $checks = [
        "SELECT COUNT(*) FROM ctrl_countries c
           LEFT JOIN ctrl_admins a ON a.id = c.created_by_admin_id
          WHERE c.created_by_admin_id IS NOT NULL AND a.id IS NULL",
        "SELECT COUNT(*) FROM ctrl_countries c
           LEFT JOIN ctrl_admins a ON a.id = c.updated_by_admin_id
          WHERE c.updated_by_admin_id IS NOT NULL AND a.id IS NULL",
        "SELECT COUNT(*) FROM ctrl_country_db_registry c
           LEFT JOIN ctrl_admins a ON a.id = c.created_by_admin_id
          WHERE c.created_by_admin_id IS NOT NULL AND a.id IS NULL",
        "SELECT COUNT(*) FROM ctrl_country_db_registry c
           LEFT JOIN ctrl_admins a ON a.id = c.updated_by_admin_id
          WHERE c.updated_by_admin_id IS NOT NULL AND a.id IS NULL",
        "SELECT COUNT(*) FROM ctrl_country_db_registry_history c
           LEFT JOIN ctrl_admins a ON a.id = c.changed_by_admin_id
          WHERE c.changed_by_admin_id IS NOT NULL AND a.id IS NULL",
        "SELECT COUNT(*) FROM ctrl_audit_events c
           LEFT JOIN ctrl_admins a ON a.id = c.actor_admin_id
          WHERE c.actor_admin_id IS NOT NULL AND a.id IS NULL",
    ];
    foreach ($checks as $sql) {
        $n = (int) $pdo->query($sql)->fetchColumn();
        if ($n > 0) {
            throw new OrangeControlTrustException('identity_actor_orphan_detected');
        }
    }
}

function orange_control_identity_fk_exists(PDO $pdo, string $table, string $constraint): bool
{
    $schema = orange_control_current_schema_name($pdo);
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    );
    $st->execute([$schema, $table, $constraint]);

    return (int) $st->fetchColumn() > 0;
}

function orange_control_identity_install_trust_actor_fks(PDO $pdo): void
{
    // Trust-Core actor columns were signed INT NULL in rev2; ctrl_admins.id is UNSIGNED.
    $alters = [
        'ALTER TABLE ctrl_countries MODIFY created_by_admin_id INT UNSIGNED NULL',
        'ALTER TABLE ctrl_countries MODIFY updated_by_admin_id INT UNSIGNED NULL',
        'ALTER TABLE ctrl_country_db_registry MODIFY created_by_admin_id INT UNSIGNED NULL',
        'ALTER TABLE ctrl_country_db_registry MODIFY updated_by_admin_id INT UNSIGNED NULL',
        'ALTER TABLE ctrl_country_db_registry_history MODIFY changed_by_admin_id INT UNSIGNED NULL',
        'ALTER TABLE ctrl_audit_events MODIFY actor_admin_id INT UNSIGNED NULL',
    ];
    foreach ($alters as $sql) {
        $pdo->exec($sql);
    }

    $fks = [
        ['ctrl_countries', 'fk_ctrl_countries_created_by', 'created_by_admin_id'],
        ['ctrl_countries', 'fk_ctrl_countries_updated_by', 'updated_by_admin_id'],
        ['ctrl_country_db_registry', 'fk_ctrl_registry_created_by', 'created_by_admin_id'],
        ['ctrl_country_db_registry', 'fk_ctrl_registry_updated_by', 'updated_by_admin_id'],
        ['ctrl_country_db_registry_history', 'fk_ctrl_reghist_changed_by', 'changed_by_admin_id'],
        ['ctrl_audit_events', 'fk_ctrl_audit_actor_admin', 'actor_admin_id'],
    ];
    foreach ($fks as [$table, $name, $col]) {
        if (orange_control_identity_fk_exists($pdo, $table, $name)) {
            continue;
        }
        $pdo->exec(
            "ALTER TABLE `{$table}`
             ADD CONSTRAINT `{$name}`
             FOREIGN KEY (`{$col}`) REFERENCES ctrl_admins(id)
             ON DELETE RESTRICT ON UPDATE RESTRICT"
        );
    }
}

function orange_control_identity_drop_routines(PDO $pdo): void
{
    foreach (orange_control_identity_all_routine_names() as $name) {
        $pdo->exec('DROP PROCEDURE IF EXISTS `' . str_replace('`', '``', $name) . '`');
    }
}

/** SQL fragment: resolve human actor from session token hash into v_actor / v_actor_su. */
function orange_control_identity_sql_session_proof_select(): string
{
    return "SELECT s.admin_id, a.is_superuser INTO v_actor, v_actor_su
              FROM ctrl_admin_sessions s
              INNER JOIN ctrl_admins a ON a.id = s.admin_id
             WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
               AND s.revoked_at IS NULL
               AND s.expires_at > NOW(6)
               AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
               AND a.is_active = 1
               AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
             LIMIT 1
             FOR UPDATE";
}

/**
 * Cursor lock of active supers in id order (no result set to CALL client) + COUNT into v_n_active.
 * Requires procedure locals: v_lock_done, v_lock_id, v_n_active, cur_active_supers, NOT FOUND handler.
 */
function orange_control_identity_sql_lock_active_supers(): string
{
    return "SET v_lock_done = 0;
            OPEN cur_active_supers;
            lock_active_supers: LOOP
                FETCH cur_active_supers INTO v_lock_id;
                IF v_lock_done = 1 THEN
                    LEAVE lock_active_supers;
                END IF;
            END LOOP;
            CLOSE cur_active_supers;
            SET v_lock_done = 0;
            SELECT COUNT(*) INTO v_n_active
              FROM ctrl_admins
             WHERE is_superuser = 1 AND is_active = 1";
}

/**
 * Install / replace all Central Identity DEFINER routines (10).
 */
function orange_control_identity_install_routines(PDO $pdo): void
{
    orange_control_identity_drop_routines($pdo);
    $proof = orange_control_identity_sql_session_proof_select();

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_emit_event(
            IN p_event_type VARCHAR(64),
            IN p_target_admin_id INT UNSIGNED,
            IN p_human_actor_admin_id INT UNSIGNED,
            IN p_actor_kind VARCHAR(16),
            IN p_correlation_id CHAR(36),
            IN p_payload_json JSON
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_type VARCHAR(64);
            DECLARE v_kind VARCHAR(16);
            DECLARE v_eu CHAR(36);
            DECLARE v_target_uuid CHAR(36);
            DECLARE v_human_uuid CHAR(36);
            DECLARE v_corr CHAR(36);
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                RESIGNAL;
            END;

            SET v_type = LOWER(TRIM(p_event_type));
            IF v_type NOT IN (
                'admin_created','admin_updated','admin_disabled','admin_enabled',
                'password_changed','superuser_granted','superuser_revoked',
                'global_access_granted','global_access_revoked','permissions_replaced',
                'country_access_changed','session_issued','session_revoked','login_throttle_cleared'
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_event_type_forbidden';
            END IF;

            SET v_kind = LOWER(TRIM(IFNULL(p_actor_kind, '')));
            IF v_kind NOT IN ('human','system','fixture') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_actor_kind_forbidden';
            END IF;

            IF p_correlation_id IS NOT NULL AND p_correlation_id <> '' THEN
                SET v_corr = LOWER(TRIM(p_correlation_id));
                IF v_corr NOT REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_correlation_id_invalid';
                END IF;
            ELSE
                SET v_corr = NULL;
            END IF;

            SET v_target_uuid = NULL;
            IF p_target_admin_id IS NOT NULL THEN
                SELECT admin_uuid INTO v_target_uuid FROM ctrl_admins WHERE id = p_target_admin_id LIMIT 1;
                IF v_target_uuid IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_target_admin_not_found';
                END IF;
            END IF;

            SET v_human_uuid = NULL;
            IF p_human_actor_admin_id IS NOT NULL THEN
                SELECT admin_uuid INTO v_human_uuid FROM ctrl_admins WHERE id = p_human_actor_admin_id LIMIT 1;
                IF v_human_uuid IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_human_actor_not_found';
                END IF;
            END IF;

            SET v_eu = LOWER(UUID());
            INSERT INTO ctrl_identity_events (
                event_uuid, event_type, correlation_id,
                target_admin_id, target_admin_uuid,
                human_actor_admin_id, human_actor_admin_uuid,
                invoker_mysql_user, definer_mysql_user, actor_kind,
                payload_json, created_at
            ) VALUES (
                v_eu, v_type, v_corr,
                p_target_admin_id, v_target_uuid,
                p_human_actor_admin_id, v_human_uuid,
                USER(), CURRENT_USER(), v_kind,
                p_payload_json, NOW(6)
            );
        END"
    );

    $lockSupers = orange_control_identity_sql_lock_active_supers();
    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_admin_upsert(
            IN p_session_token_hash CHAR(64),
            IN p_admin_uuid CHAR(36),
            IN p_username VARCHAR(100),
            IN p_password_hash VARCHAR(255),
            IN p_display_name VARCHAR(100),
            IN p_is_active TINYINT,
            IN p_correlation_id CHAR(36),
            OUT p_admin_id INT UNSIGNED
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_uname VARCHAR(100);
            DECLARE v_uuid CHAR(36);
            DECLARE v_id INT UNSIGNED;
            DECLARE v_exist_active TINYINT;
            DECLARE v_exist_su TINYINT;
            DECLARE v_want_active TINYINT;
            DECLARE v_n_active INT DEFAULT 0;
            DECLARE v_n_after INT DEFAULT 0;
            DECLARE v_lock_id INT UNSIGNED;
            DECLARE v_lock_done INT DEFAULT 0;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE cur_active_supers CURSOR FOR
                SELECT id FROM ctrl_admins
                 WHERE is_superuser = 1 AND is_active = 1
                 ORDER BY id
                 FOR UPDATE;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_lock_done = 1;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;

            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
            END IF;

            START TRANSACTION;
            SET v_trx = 1;

            {$proof};
            IF v_actor IS NULL OR v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;

            SET v_uname = LOWER(TRIM(IFNULL(p_username, '')));
            IF v_uname = '' OR v_uname NOT REGEXP '^[a-z][a-z0-9._-]{0,99}$' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_username_invalid';
            END IF;

            IF p_admin_uuid IS NULL OR TRIM(p_admin_uuid) = '' THEN
                SET v_uuid = LOWER(UUID());
                IF p_password_hash IS NULL OR TRIM(p_password_hash) = '' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_password_hash_required';
                END IF;
                INSERT INTO ctrl_admins (
                    admin_uuid, username, password_hash, display_name,
                    is_active, is_superuser, is_global_access,
                    disabled_at, password_changed_at, created_at, updated_at, row_version
                ) VALUES (
                    v_uuid, v_uname, p_password_hash, p_display_name,
                    IF(IFNULL(p_is_active,1)=1,1,0), 0, 0,
                    IF(IFNULL(p_is_active,1)=1, NULL, NOW(6)),
                    NOW(6), NOW(6), NOW(6), 1
                );
                SET v_id = LAST_INSERT_ID();
                CALL orange_ctrl_identity_emit_event(
                    'admin_created', v_id, v_actor, 'human', p_correlation_id,
                    JSON_OBJECT('username', v_uname)
                );
            ELSE
                SET v_uuid = LOWER(TRIM(p_admin_uuid));
                SELECT id, is_active, is_superuser
                  INTO v_id, v_exist_active, v_exist_su
                  FROM ctrl_admins WHERE admin_uuid = v_uuid LIMIT 1 FOR UPDATE;
                IF v_id IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_admin_not_found';
                END IF;
                SET v_want_active = IF(IFNULL(p_is_active, v_exist_active)=1,1,0);
                IF v_exist_su = 1 AND (
                    (v_want_active = 0 AND v_exist_active = 1)
                    OR (v_want_active = 1 AND v_exist_active = 0)
                ) THEN
                    {$lockSupers};
                    SET v_n_after = v_n_active;
                    IF v_want_active = 0 AND v_exist_active = 1 THEN
                        SET v_n_after = v_n_active - 1;
                    ELSEIF v_want_active = 1 AND v_exist_active = 0 THEN
                        SET v_n_after = v_n_active + 1;
                    END IF;
                    IF v_n_after < 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'last_active_superuser_required';
                    END IF;
                END IF;
                UPDATE ctrl_admins SET
                    username = v_uname,
                    display_name = p_display_name,
                    password_hash = IF(p_password_hash IS NULL OR TRIM(p_password_hash) = '', password_hash, p_password_hash),
                    password_changed_at = IF(p_password_hash IS NULL OR TRIM(p_password_hash) = '', password_changed_at, NOW(6)),
                    is_active = v_want_active,
                    disabled_at = IF(v_want_active=1, NULL, IFNULL(disabled_at, NOW(6))),
                    updated_at = NOW(6),
                    row_version = row_version + 1
                WHERE id = v_id;
                IF p_password_hash IS NOT NULL AND TRIM(p_password_hash) <> '' THEN
                    UPDATE ctrl_admin_sessions SET revoked_at = NOW(6), revoke_reason = 'password_changed'
                     WHERE admin_id = v_id AND revoked_at IS NULL;
                    CALL orange_ctrl_identity_emit_event(
                        'password_changed', v_id, v_actor, 'human', p_correlation_id, NULL
                    );
                END IF;
                IF v_want_active = 0 AND v_exist_active = 1 THEN
                    UPDATE ctrl_admin_sessions SET revoked_at = NOW(6), revoke_reason = 'admin_disabled'
                     WHERE admin_id = v_id AND revoked_at IS NULL;
                    CALL orange_ctrl_identity_emit_event(
                        'admin_disabled', v_id, v_actor, 'human', p_correlation_id, NULL
                    );
                ELSEIF v_want_active = 1 AND v_exist_active = 0 THEN
                    CALL orange_ctrl_identity_emit_event(
                        'admin_enabled', v_id, v_actor, 'human', p_correlation_id, NULL
                    );
                ELSE
                    CALL orange_ctrl_identity_emit_event(
                        'admin_updated', v_id, v_actor, 'human', p_correlation_id,
                        JSON_OBJECT('username', v_uname)
                    );
                END IF;
            END IF;

            SET p_admin_id = v_id;
            COMMIT;
            SET v_trx = 0;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_set_superuser(
            IN p_session_token_hash CHAR(64),
            IN p_target_admin_id INT UNSIGNED,
            IN p_is_superuser TINYINT,
            IN p_correlation_id CHAR(36)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_target_su TINYINT;
            DECLARE v_target_active TINYINT;
            DECLARE v_want TINYINT;
            DECLARE v_n_active INT DEFAULT 0;
            DECLARE v_n_after INT DEFAULT 0;
            DECLARE v_lock_id INT UNSIGNED;
            DECLARE v_lock_done INT DEFAULT 0;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE cur_active_supers CURSOR FOR
                SELECT id FROM ctrl_admins
                 WHERE is_superuser = 1 AND is_active = 1
                 ORDER BY id
                 FOR UPDATE;
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_lock_done = 1;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            {$proof};
            IF v_actor IS NULL OR v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;
            IF p_target_admin_id IS NULL OR p_target_admin_id = v_actor THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_self_escalation_denied';
            END IF;
            {$lockSupers};
            SELECT is_superuser, is_active INTO v_target_su, v_target_active
              FROM ctrl_admins WHERE id = p_target_admin_id LIMIT 1 FOR UPDATE;
            IF v_target_su IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_admin_not_found';
            END IF;
            SET v_want = IF(IFNULL(p_is_superuser,0)=1,1,0);
            SET v_n_after = v_n_active;
            IF v_want = 0 AND v_target_su = 1 AND v_target_active = 1 THEN
                SET v_n_after = v_n_active - 1;
            ELSEIF v_want = 1 AND NOT (v_target_su = 1 AND v_target_active = 1) AND v_target_active = 1 THEN
                SET v_n_after = v_n_active + 1;
            END IF;
            IF v_n_after < 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'last_active_superuser_required';
            END IF;
            UPDATE ctrl_admins SET is_superuser = v_want, updated_at = NOW(6), row_version = row_version + 1
             WHERE id = p_target_admin_id;
            CALL orange_ctrl_identity_emit_event(
                IF(v_want=1,'superuser_granted','superuser_revoked'),
                p_target_admin_id, v_actor, 'human', p_correlation_id, NULL
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_set_global_access(
            IN p_session_token_hash CHAR(64),
            IN p_target_admin_id INT UNSIGNED,
            IN p_is_global_access TINYINT,
            IN p_correlation_id CHAR(36)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_want TINYINT;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            {$proof};
            IF v_actor IS NULL OR v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;
            IF p_target_admin_id IS NULL OR p_target_admin_id = v_actor THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_self_escalation_denied';
            END IF;
            IF (SELECT id FROM ctrl_admins WHERE id = p_target_admin_id LIMIT 1) IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_admin_not_found';
            END IF;
            SET v_want = IF(IFNULL(p_is_global_access,0)=1,1,0);
            UPDATE ctrl_admins SET is_global_access = v_want, updated_at = NOW(6), row_version = row_version + 1
             WHERE id = p_target_admin_id;
            CALL orange_ctrl_identity_emit_event(
                IF(v_want=1,'global_access_granted','global_access_revoked'),
                p_target_admin_id, v_actor, 'human', p_correlation_id, NULL
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    orange_control_identity_install_routines_part2($pdo, $proof);
}

/**
 * Remaining identity routines (password, permissions, country, session, throttle).
 */
function orange_control_identity_install_routines_part2(PDO $pdo, string $proof): void
{
    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_set_password(
            IN p_session_token_hash CHAR(64),
            IN p_target_admin_id INT UNSIGNED,
            IN p_password_hash VARCHAR(255),
            IN p_correlation_id CHAR(36)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            {$proof};
            IF v_actor IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;
            IF p_password_hash IS NULL OR TRIM(p_password_hash) = '' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_password_hash_required';
            END IF;
            IF p_target_admin_id IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_admin_not_found';
            END IF;
            IF p_target_admin_id <> v_actor AND v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;
            IF (SELECT id FROM ctrl_admins WHERE id = p_target_admin_id LIMIT 1) IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_admin_not_found';
            END IF;
            UPDATE ctrl_admins SET password_hash = p_password_hash, password_changed_at = NOW(6),
                   updated_at = NOW(6), row_version = row_version + 1
             WHERE id = p_target_admin_id;
            UPDATE ctrl_admin_sessions SET revoked_at = NOW(6), revoke_reason = 'password_changed'
             WHERE admin_id = p_target_admin_id AND revoked_at IS NULL;
            CALL orange_ctrl_identity_emit_event(
                'password_changed', p_target_admin_id, v_actor, 'human', p_correlation_id, NULL
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_permissions_replace(
            IN p_session_token_hash CHAR(64),
            IN p_target_admin_id INT UNSIGNED,
            IN p_permissions_json JSON,
            IN p_correlation_id CHAR(36)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_i INT DEFAULT 0;
            DECLARE v_n INT DEFAULT 0;
            DECLARE v_key VARCHAR(80);
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            {$proof};
            IF v_actor IS NULL OR v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;
            IF p_target_admin_id IS NULL OR p_target_admin_id = v_actor THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_self_escalation_denied';
            END IF;
            IF (SELECT id FROM ctrl_admins WHERE id = p_target_admin_id LIMIT 1) IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_admin_not_found';
            END IF;
            IF JSON_TYPE(p_permissions_json) <> 'ARRAY' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_permissions_json_invalid';
            END IF;
            SET v_n = JSON_LENGTH(p_permissions_json);
            SET v_i = 0;
            WHILE v_i < v_n DO
                SET v_key = JSON_UNQUOTE(JSON_EXTRACT(p_permissions_json, CONCAT('$[', v_i, '].resource_key')));
                IF v_key IS NULL OR v_key NOT REGEXP '^page:[a-z][a-z0-9_]{0,74}$' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_resource_key_invalid';
                END IF;
                SET v_i = v_i + 1;
            END WHILE;
            DELETE FROM ctrl_admin_permissions WHERE admin_id = p_target_admin_id;
            SET v_i = 0;
            WHILE v_i < v_n DO
                SET v_key = JSON_UNQUOTE(JSON_EXTRACT(p_permissions_json, CONCAT('$[', v_i, '].resource_key')));
                INSERT INTO ctrl_admin_permissions (
                    admin_id, resource_key, can_view, can_edit, can_delete, can_lock, can_unlock, can_print, can_export
                ) VALUES (
                    p_target_admin_id, v_key,
                    IF(IFNULL(JSON_EXTRACT(p_permissions_json, CONCAT('$[', v_i, '].can_view')),0)=1,1,0),
                    IF(IFNULL(JSON_EXTRACT(p_permissions_json, CONCAT('$[', v_i, '].can_edit')),0)=1,1,0),
                    IF(IFNULL(JSON_EXTRACT(p_permissions_json, CONCAT('$[', v_i, '].can_delete')),0)=1,1,0),
                    IF(IFNULL(JSON_EXTRACT(p_permissions_json, CONCAT('$[', v_i, '].can_lock')),0)=1,1,0),
                    IF(IFNULL(JSON_EXTRACT(p_permissions_json, CONCAT('$[', v_i, '].can_unlock')),0)=1,1,0),
                    IF(IFNULL(JSON_EXTRACT(p_permissions_json, CONCAT('$[', v_i, '].can_print')),0)=1,1,0),
                    IF(IFNULL(JSON_EXTRACT(p_permissions_json, CONCAT('$[', v_i, '].can_export')),0)=1,1,0)
                );
                SET v_i = v_i + 1;
            END WHILE;
            CALL orange_ctrl_identity_emit_event(
                'permissions_replaced', p_target_admin_id, v_actor, 'human', p_correlation_id,
                JSON_OBJECT('count', v_n)
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_country_access_set(
            IN p_session_token_hash CHAR(64),
            IN p_target_admin_id INT UNSIGNED,
            IN p_country_id INT UNSIGNED,
            IN p_can_access TINYINT,
            IN p_is_default TINYINT,
            IN p_correlation_id CHAR(36)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_life VARCHAR(32);
            DECLARE v_can TINYINT;
            DECLARE v_def TINYINT;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            {$proof};
            IF v_actor IS NULL OR v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;
            IF p_target_admin_id IS NULL OR p_target_admin_id = v_actor THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_self_escalation_denied';
            END IF;
            IF (SELECT id FROM ctrl_admins WHERE id = p_target_admin_id LIMIT 1) IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_admin_not_found';
            END IF;
            SELECT lifecycle_status INTO v_life FROM ctrl_countries WHERE id = p_country_id LIMIT 1 FOR UPDATE;
            IF v_life IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_country_not_found';
            END IF;
            IF v_life = 'retired' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_country_retired_forbidden';
            END IF;
            SET v_can = IF(IFNULL(p_can_access,0)=1,1,0);
            SET v_def = IF(IFNULL(p_is_default,0)=1,1,0);
            IF v_def = 1 AND v_can <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_default_requires_access';
            END IF;
            IF v_def = 1 AND v_life = 'suspended' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_suspended_default_forbidden';
            END IF;
            IF v_def = 1 AND v_life NOT IN ('ready','active') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_default_lifecycle_forbidden';
            END IF;
            INSERT INTO ctrl_admin_country_access (admin_id, country_id, can_access, is_default, created_at)
            VALUES (p_target_admin_id, p_country_id, v_can, 0, NOW(6))
            ON DUPLICATE KEY UPDATE can_access = v_can;
            IF v_def = 1 THEN
                UPDATE ctrl_admin_country_access SET is_default = 0
                 WHERE admin_id = p_target_admin_id AND country_id <> p_country_id AND is_default = 1;
                UPDATE ctrl_admin_country_access SET is_default = 1
                 WHERE admin_id = p_target_admin_id AND country_id = p_country_id;
            ELSEIF v_can = 0 THEN
                UPDATE ctrl_admin_country_access SET is_default = 0
                 WHERE admin_id = p_target_admin_id AND country_id = p_country_id;
                UPDATE ctrl_admin_sessions
                   SET selected_country_id = NULL, selected_country_uuid = NULL
                 WHERE admin_id = p_target_admin_id AND selected_country_id = p_country_id AND revoked_at IS NULL;
            END IF;
            CALL orange_ctrl_identity_emit_event(
                'country_access_changed', p_target_admin_id, v_actor, 'human', p_correlation_id,
                JSON_OBJECT('country_id', p_country_id, 'can_access', v_can, 'is_default', v_def)
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    orange_control_identity_install_routines_part3($pdo, $proof);
}

function orange_control_identity_install_routines_part3(PDO $pdo, string $proof): void
{
    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_session_issue(
            IN p_session_token_hash CHAR(64),
            IN p_admin_id INT UNSIGNED,
            IN p_expires_at DATETIME(6),
            IN p_selected_country_id INT UNSIGNED,
            IN p_selected_country_uuid CHAR(36),
            IN p_ip_hash CHAR(64),
            IN p_user_agent_hash CHAR(64),
            IN p_actor_kind VARCHAR(16),
            IN p_issuer_session_token_hash CHAR(64),
            IN p_correlation_id CHAR(36),
            OUT p_session_id BIGINT UNSIGNED
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_kind VARCHAR(16);
            DECLARE v_hash CHAR(64);
            DECLARE v_fp CHAR(16);
            DECLARE v_cuuid CHAR(36);
            DECLARE v_life VARCHAR(32);
            DECLARE v_can TINYINT;
            DECLARE v_ga TINYINT;
            DECLARE v_su TINYINT;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;

            SET v_kind = LOWER(TRIM(IFNULL(p_actor_kind, '')));
            IF v_kind NOT IN ('human','system','fixture') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_actor_kind_forbidden';
            END IF;
            IF p_issuer_session_token_hash IS NULL OR TRIM(p_issuer_session_token_hash) = '' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_issuer_session_required';
            END IF;

            SELECT s.admin_id, a.is_superuser INTO v_actor, v_actor_su
              FROM ctrl_admin_sessions s
              INNER JOIN ctrl_admins a ON a.id = s.admin_id
             WHERE s.session_token_hash = LOWER(TRIM(p_issuer_session_token_hash))
               AND s.revoked_at IS NULL AND s.expires_at > NOW(6)
               AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
               AND a.is_active = 1
               AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
             LIMIT 1 FOR UPDATE;
            IF v_actor IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;
            IF p_admin_id IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_admin_not_found';
            END IF;
            IF p_admin_id <> v_actor AND v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;

            SELECT is_active, is_superuser, is_global_access INTO v_can, v_su, v_ga
              FROM ctrl_admins WHERE id = p_admin_id LIMIT 1 FOR UPDATE;
            IF v_can IS NULL OR v_can <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_admin_inactive';
            END IF;

            SET v_hash = LOWER(TRIM(p_session_token_hash));
            IF v_hash NOT REGEXP '^[0-9a-f]{64}$' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_session_token_hash_invalid';
            END IF;
            SET v_fp = LEFT(v_hash, 16);

            IF (p_selected_country_id IS NULL) XOR (p_selected_country_uuid IS NULL OR TRIM(IFNULL(p_selected_country_uuid,'')) = '') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_session_country_pair_invalid';
            END IF;
            IF p_selected_country_id IS NOT NULL THEN
                SELECT country_uuid, lifecycle_status INTO v_cuuid, v_life
                  FROM ctrl_countries WHERE id = p_selected_country_id LIMIT 1;
                IF v_cuuid IS NULL OR LOWER(TRIM(p_selected_country_uuid)) <> v_cuuid THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_session_country_pair_mismatch';
                END IF;
                IF v_su <> 1 AND v_ga <> 1 THEN
                    IF (SELECT can_access FROM ctrl_admin_country_access
                         WHERE admin_id = p_admin_id AND country_id = p_selected_country_id LIMIT 1) <> 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_country_access_denied';
                    END IF;
                END IF;
            END IF;

            INSERT INTO ctrl_admin_sessions (
                admin_id, session_token_hash, token_fingerprint, created_at, expires_at, last_seen_at,
                idle_timeout_sec, selected_country_id, selected_country_uuid, ip_hash, user_agent_hash
            ) VALUES (
                p_admin_id, v_hash, v_fp, NOW(6), p_expires_at, NOW(6),
                43200, p_selected_country_id,
                IF(p_selected_country_uuid IS NULL, NULL, LOWER(TRIM(p_selected_country_uuid))),
                IF(p_ip_hash IS NULL, NULL, LOWER(TRIM(p_ip_hash))),
                IF(p_user_agent_hash IS NULL, NULL, LOWER(TRIM(p_user_agent_hash)))
            );
            SET p_session_id = LAST_INSERT_ID();
            CALL orange_ctrl_identity_emit_event(
                'session_issued', p_admin_id, v_actor, 'human', p_correlation_id,
                JSON_OBJECT('session_id', p_session_id)
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_session_revoke(
            IN p_session_token_hash CHAR(64),
            IN p_target_session_id BIGINT UNSIGNED,
            IN p_revoke_reason VARCHAR(32),
            IN p_correlation_id CHAR(36)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_actor INT UNSIGNED;
            DECLARE v_actor_su TINYINT;
            DECLARE v_sid BIGINT UNSIGNED;
            DECLARE v_owner INT UNSIGNED;
            DECLARE v_reason VARCHAR(32);
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;
            IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
            END IF;
            START TRANSACTION; SET v_trx = 1;
            {$proof};
            IF v_actor IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;
            SET v_reason = LOWER(TRIM(IFNULL(p_revoke_reason, 'revoked')));
            IF v_reason = '' OR v_reason NOT REGEXP '^[a-z][a-z0-9_]{0,30}$' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_revoke_reason_invalid';
            END IF;
            IF p_target_session_id IS NOT NULL THEN
                SET v_sid = p_target_session_id;
            ELSE
                SELECT id INTO v_sid FROM ctrl_admin_sessions
                 WHERE session_token_hash = LOWER(TRIM(p_session_token_hash)) LIMIT 1 FOR UPDATE;
            END IF;
            SELECT id, admin_id INTO v_sid, v_owner FROM ctrl_admin_sessions WHERE id = v_sid LIMIT 1 FOR UPDATE;
            IF v_sid IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_session_not_found';
            END IF;
            IF v_owner <> v_actor AND v_actor_su <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_authorization_denied';
            END IF;
            UPDATE ctrl_admin_sessions SET revoked_at = NOW(6), revoke_reason = v_reason
             WHERE id = v_sid AND revoked_at IS NULL;
            CALL orange_ctrl_identity_emit_event(
                'session_revoked', v_owner, v_actor, 'human', p_correlation_id,
                JSON_OBJECT('session_id', v_sid, 'reason', v_reason)
            );
            COMMIT; SET v_trx = 0;
        END"
    );

    // Defaults match includes/admin_login_rate_limit.php (5/30/900/900).
    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_identity_login_throttle_apply(
            IN p_operation VARCHAR(32),
            IN p_username VARCHAR(100),
            IN p_ip_addr VARCHAR(64),
            IN p_max_attempts_username INT,
            IN p_max_attempts_ip INT,
            IN p_window_seconds INT,
            IN p_lock_seconds INT,
            OUT p_allowed TINYINT,
            OUT p_locked TINYINT,
            OUT p_retry_after_seconds INT
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_op VARCHAR(32);
            DECLARE v_user_key VARCHAR(100);
            DECLARE v_ip_key CHAR(64);
            DECLARE v_max_u INT;
            DECLARE v_max_i INT;
            DECLARE v_win INT;
            DECLARE v_lock INT;
            DECLARE v_fc INT;
            DECLARE v_ws DATETIME(6);
            DECLARE v_lu DATETIME(6);
            DECLARE v_id BIGINT UNSIGNED;
            DECLARE v_trx INT DEFAULT 0;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                IF v_trx = 1 THEN ROLLBACK; END IF;
                RESIGNAL;
            END;

            SET v_op = LOWER(TRIM(IFNULL(p_operation, '')));
            IF v_op NOT IN ('check','record_failure','reset_success') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_throttle_operation_invalid';
            END IF;
            SET v_max_u = IFNULL(p_max_attempts_username, 5);
            SET v_max_i = IFNULL(p_max_attempts_ip, 30);
            SET v_win = IFNULL(p_window_seconds, 900);
            SET v_lock = IFNULL(p_lock_seconds, 900);
            SET v_user_key = LOWER(TRIM(IFNULL(p_username, '')));
            IF v_user_key <> '' AND v_user_key NOT REGEXP '^[a-z][a-z0-9._-]{0,99}$' THEN
                SET v_user_key = '';
            END IF;
            SET v_ip_key = LOWER(SHA2(IFNULL(p_ip_addr, '0.0.0.0'), 256));
            SET p_allowed = 1; SET p_locked = 0; SET p_retry_after_seconds = 0;

            IF v_op = 'check' THEN
                IF v_user_key <> '' THEN
                    SELECT failed_count, window_started_at, locked_until INTO v_fc, v_ws, v_lu
                      FROM ctrl_admin_login_throttle WHERE scope_type='username' AND scope_key=v_user_key LIMIT 1;
                    IF v_lu IS NOT NULL AND v_lu > NOW(6) THEN
                        SET p_allowed = 0; SET p_locked = 1;
                        SET p_retry_after_seconds = TIMESTAMPDIFF(SECOND, NOW(6), v_lu);
                    ELSEIF v_ws IS NOT NULL AND v_fc > 0 AND TIMESTAMPDIFF(SECOND, v_ws, NOW(6)) <= v_win AND v_fc >= v_max_u THEN
                        SET p_allowed = 0; SET p_locked = 1; SET p_retry_after_seconds = v_lock;
                    END IF;
                END IF;
                IF p_allowed = 1 THEN
                    SELECT failed_count, window_started_at, locked_until INTO v_fc, v_ws, v_lu
                      FROM ctrl_admin_login_throttle WHERE scope_type='ip' AND scope_key=v_ip_key LIMIT 1;
                    IF v_lu IS NOT NULL AND v_lu > NOW(6) THEN
                        SET p_allowed = 0; SET p_locked = 1;
                        SET p_retry_after_seconds = TIMESTAMPDIFF(SECOND, NOW(6), v_lu);
                    ELSEIF v_ws IS NOT NULL AND v_fc > 0 AND TIMESTAMPDIFF(SECOND, v_ws, NOW(6)) <= v_win AND v_fc >= v_max_i THEN
                        SET p_allowed = 0; SET p_locked = 1; SET p_retry_after_seconds = v_lock;
                    END IF;
                END IF;
            ELSEIF v_op = 'record_failure' THEN
                IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
                END IF;
                START TRANSACTION; SET v_trx = 1;
                IF v_user_key <> '' THEN
                    SELECT id, failed_count, window_started_at INTO v_id, v_fc, v_ws
                      FROM ctrl_admin_login_throttle WHERE scope_type='username' AND scope_key=v_user_key
                      LIMIT 1 FOR UPDATE;
                    IF v_id IS NULL THEN
                        INSERT INTO ctrl_admin_login_throttle
                            (scope_type, scope_key, failed_count, window_started_at, locked_until, created_at, updated_at)
                        VALUES ('username', v_user_key, 1, NOW(6),
                            IF(1 >= v_max_u, DATE_ADD(NOW(6), INTERVAL v_lock SECOND), NULL), NOW(6), NOW(6));
                    ELSE
                        IF v_ws IS NULL OR TIMESTAMPDIFF(SECOND, v_ws, NOW(6)) > v_win THEN
                            SET v_fc = 0; SET v_ws = NOW(6);
                        END IF;
                        SET v_fc = v_fc + 1;
                        UPDATE ctrl_admin_login_throttle SET failed_count=v_fc, window_started_at=v_ws,
                            locked_until=IF(v_fc >= v_max_u, DATE_ADD(NOW(6), INTERVAL v_lock SECOND), NULL),
                            updated_at=NOW(6) WHERE id=v_id;
                    END IF;
                END IF;
                SELECT id, failed_count, window_started_at INTO v_id, v_fc, v_ws
                  FROM ctrl_admin_login_throttle WHERE scope_type='ip' AND scope_key=v_ip_key
                  LIMIT 1 FOR UPDATE;
                IF v_id IS NULL THEN
                    INSERT INTO ctrl_admin_login_throttle
                        (scope_type, scope_key, failed_count, window_started_at, locked_until, created_at, updated_at)
                    VALUES ('ip', v_ip_key, 1, NOW(6),
                        IF(1 >= v_max_i, DATE_ADD(NOW(6), INTERVAL v_lock SECOND), NULL), NOW(6), NOW(6));
                ELSE
                    IF v_ws IS NULL OR TIMESTAMPDIFF(SECOND, v_ws, NOW(6)) > v_win THEN
                        SET v_fc = 0; SET v_ws = NOW(6);
                    END IF;
                    SET v_fc = v_fc + 1;
                    UPDATE ctrl_admin_login_throttle SET failed_count=v_fc, window_started_at=v_ws,
                        locked_until=IF(v_fc >= v_max_i, DATE_ADD(NOW(6), INTERVAL v_lock SECOND), NULL),
                        updated_at=NOW(6) WHERE id=v_id;
                END IF;
                COMMIT; SET v_trx = 0;
                SET p_allowed = 1; SET p_locked = 0; SET p_retry_after_seconds = 0;
            ELSE
                IF COALESCE(@orange_ctrl_identity_outer_txn, 0) = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'identity_outer_transaction_forbidden';
                END IF;
                START TRANSACTION; SET v_trx = 1;
                IF v_user_key <> '' THEN
                    DELETE FROM ctrl_admin_login_throttle WHERE scope_type='username' AND scope_key=v_user_key LIMIT 1;
                END IF;
                DELETE FROM ctrl_admin_login_throttle WHERE scope_type='ip' AND scope_key=v_ip_key LIMIT 1;
                COMMIT; SET v_trx = 0;
                SET p_allowed = 1; SET p_locked = 0; SET p_retry_after_seconds = 0;
            END IF;
        END"
    );
}

/**
 * Fresh Control Identity install (tables + routines + orphan gate + Trust actor FKs).
 * Used only on empty/meta-absent Control DB paths — never on completed rev3 verify.
 */
function orange_control_identity_install_fresh(PDO $pdo): void
{
    orange_control_identity_install_tables($pdo);
    orange_control_identity_install_routines($pdo);
    orange_control_identity_orphan_gate($pdo);
    orange_control_identity_install_trust_actor_fks($pdo);
}

/**
 * Migrate Trust-Core rev2 → Identity package (same physical install as fresh).
 */
function orange_control_identity_migrate_2to3(PDO $pdo): void
{
    orange_control_identity_install_fresh($pdo);
}

/**
 * Verify completed rev3 Identity package. MUST NOT CREATE / ALTER / DROP / heal.
 *
 * @throws OrangeControlTrustException schema_partial_or_incompatible
 */
function orange_control_identity_verify_rev3(PDO $pdo): void
{
    $schema = orange_control_current_schema_name($pdo);
    foreach (orange_control_identity_table_names() as $table) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'"
        );
        $st->execute([$schema, $table]);
        if ((int) $st->fetchColumn() !== 1) {
            throw new OrangeControlTrustException('schema_partial_or_incompatible');
        }
    }
    foreach (orange_control_identity_all_routine_names() as $routine) {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = ? AND ROUTINE_NAME = ? AND ROUTINE_TYPE = 'PROCEDURE'"
        );
        $st->execute([$schema, $routine]);
        if ((int) $st->fetchColumn() !== 1) {
            throw new OrangeControlTrustException('schema_partial_or_incompatible');
        }
    }
    $requiredFks = [
        ['ctrl_countries', 'fk_ctrl_countries_created_by'],
        ['ctrl_countries', 'fk_ctrl_countries_updated_by'],
        ['ctrl_country_db_registry', 'fk_ctrl_registry_created_by'],
        ['ctrl_country_db_registry', 'fk_ctrl_registry_updated_by'],
        ['ctrl_country_db_registry_history', 'fk_ctrl_reghist_changed_by'],
        ['ctrl_audit_events', 'fk_ctrl_audit_actor_admin'],
    ];
    foreach ($requiredFks as [$table, $name]) {
        if (!orange_control_identity_fk_exists($pdo, $table, $name)) {
            throw new OrangeControlTrustException('schema_partial_or_incompatible');
        }
    }
}

/**
 * Dispatcher retained for callers that still name ensure_package.
 * Prefer install_fresh / migrate_2to3 / verify_rev3 from control_schema state machine.
 * This path always installs (fresh/migrate only) — never use for stable rev3 verify.
 */
function orange_control_identity_ensure_package(PDO $pdo): void
{
    orange_control_identity_install_fresh($pdo);
}

