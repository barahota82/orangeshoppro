<?php
declare(strict_types=1);
/**
 * Phase 2.1B.4 Control Domain schema — external Owner-candidate (dormant until M02-I1).
 * ORANGE_CONTROL_SCHEMA_REVISION target integration = 5.
 */
const ORANGE_CONTROL_DOMAIN_TABLES = [
    'ctrl_domain_registry',
    'ctrl_domain_hosts',
    'ctrl_domain_authority',
    'ctrl_public_channel_route_registry',
    'ctrl_market_content',
    'ctrl_market_entry_policy',
    'ctrl_market_content_i18n',
    'ctrl_contact_whatsapp_global',
    'ctrl_contact_whatsapp_country',
];

const ORANGE_CONTROL_DOMAIN_ROUTINES = [    'orange_ctrl_domain_registry_upsert',
    'orange_ctrl_domain_registry_status_transition',
    'orange_ctrl_domain_host_upsert',
    'orange_ctrl_domain_host_status_transition',
    'orange_ctrl_domain_host_health_record',
    'orange_ctrl_domain_authority_set_mode',
    'orange_ctrl_domain_cutover_cas_begin',
    'orange_ctrl_domain_cutover_cas_commit',
    'orange_ctrl_domain_cutover_cas_abort',
    'orange_ctrl_domain_dns_challenge_issue',
    'orange_ctrl_domain_dns_challenge_verify',
    'orange_ctrl_domain_redirect_permanent_approve',
    'orange_ctrl_domain_emergency_rollback',
    'orange_ctrl_country_geo_flag_set',
    'orange_ctrl_public_channel_route_upsert',
    'orange_ctrl_public_channel_route_status_transition',
    'orange_ctrl_market_entry_policy_upsert',
    'orange_ctrl_market_entry_policy_status_transition',
    'orange_ctrl_market_content_upsert',
    'orange_ctrl_market_content_lifecycle_transition',
    'orange_ctrl_market_content_english_review_set',
    'orange_ctrl_market_content_i18n_upsert',
    'orange_ctrl_market_content_i18n_status_transition',
    'orange_ctrl_wa_global_upsert',
    'orange_ctrl_wa_global_status_transition',
    'orange_ctrl_wa_country_upsert',
    'orange_ctrl_wa_country_status_transition',
];

function orange_control_domain_nine_tables_sql(): string {
    return 'CREATE TABLE IF NOT EXISTS ctrl_domain_registry (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  domain_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  https_policy VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  registry_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  display_label VARCHAR(191) NULL,
  notes_utf8 VARCHAR(512) NULL,
  created_by_admin_id INT UNSIGNED NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  retired_at DATETIME(6) NULL,
  retired_by_admin_id INT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ctrl_domain_registry_uuid (domain_uuid),
  UNIQUE KEY uq_ctrl_domain_registry_id_env (id, environment),
  UNIQUE KEY uq_ctrl_domain_registry_env_uuid (environment, domain_uuid),
  KEY idx_ctrl_domain_registry_env_status (environment, registry_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ctrl_domain_hosts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  host_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  registry_id BIGINT UNSIGNED NOT NULL,
  environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  normalized_host VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  host_role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  host_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  redirect_policy VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'temporary_302\',
  verification_method VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'dns_txt\',
  verification_token_hash BINARY(32) NULL,
  challenge_expires_at DATETIME(6) NULL,
  verification_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  verified_at DATETIME(6) NULL,
  activated_at DATETIME(6) NULL,
  suspended_at DATETIME(6) NULL,
  permanent_redirect_approved_at DATETIME(6) NULL,
  permanent_redirect_approved_by_admin_id INT UNSIGNED NULL,
  health_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'unknown\',
  last_health_at DATETIME(6) NULL,
  health_evidence_hash BINARY(32) NULL,
  is_active TINYINT(1) GENERATED ALWAYS AS (host_status=\'active\') STORED,
  active_canonical_environment_slot VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin
    GENERATED ALWAYS AS (IF(host_role=\'canonical\' AND host_status=\'active\', environment, NULL)) STORED,
  created_by_admin_id INT UNSIGNED NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  retired_at DATETIME(6) NULL,
  retired_by_admin_id INT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ctrl_domain_hosts_uuid (host_uuid),
  UNIQUE KEY uq_ctrl_domain_hosts_host (normalized_host),
  UNIQUE KEY uq_ctrl_domain_hosts_id_env (id, environment),
  UNIQUE KEY uq_ctrl_domain_hosts_active_canonical_slot (active_canonical_environment_slot),
  KEY idx_ctrl_domain_hosts_registry (registry_id),
  KEY idx_ctrl_domain_hosts_env_status (environment, host_status),
  CONSTRAINT fk_ctrl_domain_hosts_registry_env
    FOREIGN KEY (registry_id, environment)
    REFERENCES ctrl_domain_registry (id, environment)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ctrl_domain_authority (
  environment VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  host_authority_mode VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active_canonical_host_id BIGINT UNSIGNED NULL,
  previous_canonical_host_id BIGINT UNSIGNED NULL,
  cutover_lock_token_hash BINARY(32) NULL,
  cutover_lock_expires_at DATETIME(6) NULL,
  monitoring_until DATETIME(6) NULL,
  activated_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  created_by_admin_id INT UNSIGNED NULL,
  updated_at DATETIME(6) NOT NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  emergency_env_rollback_at DATETIME(6) NULL,
  emergency_env_rollback_by_admin_id INT UNSIGNED NULL,
  last_cutover_at DATETIME(6) NULL,
  last_cutover_by_admin_id INT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (environment),
  KEY idx_ctrl_domain_auth_active (active_canonical_host_id),
  KEY idx_ctrl_domain_auth_prev (previous_canonical_host_id),
  CONSTRAINT fk_ctrl_domain_auth_active_host_env
    FOREIGN KEY (active_canonical_host_id, environment)
    REFERENCES ctrl_domain_hosts (id, environment)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_ctrl_domain_auth_prev_host_env
    FOREIGN KEY (previous_canonical_host_id, environment)
    REFERENCES ctrl_domain_hosts (id, environment)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ctrl_public_channel_route_registry (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  country_id INT UNSIGNED NOT NULL,
  country_code_snapshot VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  match_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  match_value VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  canonical_channel_slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_channel_id_snapshot INT NULL,
  route_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  redirect_policy VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'none\',
  notes_utf8 VARCHAR(512) NULL,
  created_by_admin_id INT UNSIGNED NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  retired_at DATETIME(6) NULL,
  retired_by_admin_id INT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ctrl_pcr_uuid (route_uuid),
  UNIQUE KEY uq_ctrl_pcr_match (match_kind, match_value),
  KEY idx_ctrl_pcr_country (country_code_snapshot),
  KEY idx_ctrl_pcr_status (route_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ctrl_market_content (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  content_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mother_locale VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  mother_title_utf8 VARCHAR(191) NOT NULL,
  mother_body_utf8 TEXT NOT NULL,
  source_revision INT UNSIGNED NOT NULL DEFAULT 1,
  source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  approved_en_title_utf8 VARCHAR(191) NULL,
  approved_en_body_utf8 TEXT NULL,
  approved_en_revision INT UNSIGNED NULL,
  approved_en_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  english_review_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'none\',
  english_reviewed_at DATETIME(6) NULL,
  english_reviewed_by_admin_id INT UNSIGNED NULL,
  lifecycle_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by_admin_id INT UNSIGNED NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ctrl_market_content_uuid (content_uuid),
  UNIQUE KEY uq_ctrl_market_content_key (content_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ctrl_market_entry_policy (
  country_id INT UNSIGNED NOT NULL,
  market_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  unavailable_message_mode VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'AUTO\',
  market_content_id BIGINT UNSIGNED NULL,
  allow_global_wa_fallback TINYINT(1) NOT NULL DEFAULT 0,
  international_shipping_mode VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'OFF\',
  is_market_open TINYINT(1) GENERATED ALWAYS AS (market_status=\'open\') STORED,
  created_by_admin_id INT UNSIGNED NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (country_id),
  CONSTRAINT fk_ctrl_mep_country FOREIGN KEY (country_id) REFERENCES ctrl_countries(id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_ctrl_mep_content FOREIGN KEY (market_content_id) REFERENCES ctrl_market_content(id)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ctrl_market_content_i18n (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_id BIGINT UNSIGNED NOT NULL,
  locale_code VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title_utf8 VARCHAR(191) NOT NULL,
  body_utf8 TEXT NOT NULL,
  translated_from_en_revision INT UNSIGNED NOT NULL,
  translated_from_en_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  translation_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  review_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'unreviewed\',
  translated_at DATETIME(6) NULL,
  translated_by_admin_id INT UNSIGNED NULL,
  reviewed_at DATETIME(6) NULL,
  reviewed_by_admin_id INT UNSIGNED NULL,
  created_by_admin_id INT UNSIGNED NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ctrl_mci18n_content_locale (content_id, locale_code),
  CONSTRAINT fk_ctrl_mci18n_content FOREIGN KEY (content_id) REFERENCES ctrl_market_content(id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_ctrl_mci18n_locale FOREIGN KEY (locale_code) REFERENCES ctrl_locales(locale_code)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ctrl_contact_whatsapp_global (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wa_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  display_label VARCHAR(191) NOT NULL,
  prefilled_message_content_id BIGINT UNSIGNED NULL,
  normalized_e164 VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  contact_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_active_slot TINYINT(1) GENERATED ALWAYS AS (IF(contact_status=\'active\',1,NULL)) STORED,
  created_by_admin_id INT UNSIGNED NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ctrl_wa_global_uuid (wa_uuid),
  UNIQUE KEY uq_ctrl_wa_global_active_slot (is_active_slot),
  CONSTRAINT fk_ctrl_wa_global_prefilled FOREIGN KEY (prefilled_message_content_id) REFERENCES ctrl_market_content(id)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ctrl_contact_whatsapp_country (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wa_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  country_id INT UNSIGNED NOT NULL,
  display_label VARCHAR(191) NOT NULL,
  prefilled_message_content_id BIGINT UNSIGNED NULL,
  normalized_e164 VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  contact_status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_active_slot TINYINT(1) GENERATED ALWAYS AS (IF(contact_status=\'active\',1,NULL)) STORED,
  created_by_admin_id INT UNSIGNED NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ctrl_wa_country_uuid (wa_uuid),
  UNIQUE KEY uq_ctrl_wa_country_active_slot (country_id, is_active_slot),
  KEY idx_ctrl_wa_country (country_id),
  CONSTRAINT fk_ctrl_wa_country_country FOREIGN KEY (country_id) REFERENCES ctrl_countries(id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_ctrl_wa_country_prefilled FOREIGN KEY (prefilled_message_content_id) REFERENCES ctrl_market_content(id)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
}

function orange_control_domain_apply_countries_k01(PDO $pdo): void
{
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $has = static function (string $col) use ($pdo, $db): bool {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute([$db, 'ctrl_countries', $col]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$has('geo_iso2_code')) {
        $pdo->exec("ALTER TABLE ctrl_countries ADD COLUMN geo_iso2_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL");
    }
    if (!$has('flag_emoji')) {
        $pdo->exec("ALTER TABLE ctrl_countries ADD COLUMN flag_emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
    }
    if (!$has('row_version')) {
        $pdo->exec("ALTER TABLE ctrl_countries ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1");
    } else {
        $pdo->exec("ALTER TABLE ctrl_countries MODIFY COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1");
    }
    $idx = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='ctrl_countries' AND INDEX_NAME='uq_ctrl_countries_geo_iso2_code'");
    $idx->execute([$db]);
    if ((int)$idx->fetchColumn() === 0) {
        // drop wrong legacy name if present
        $bad = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='ctrl_countries' AND INDEX_NAME='uq_ctrl_countries_geo_iso2'");
        $bad->execute([$db]);
        if ((int)$bad->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE ctrl_countries DROP INDEX uq_ctrl_countries_geo_iso2');
        }
        $pdo->exec('ALTER TABLE ctrl_countries ADD UNIQUE KEY uq_ctrl_countries_geo_iso2_code (geo_iso2_code)');
    }
}

function orange_control_domain_install_country_trigger(PDO $pdo): void
{
    $pdo->exec('DROP TRIGGER IF EXISTS trg_ctrl_countries_bu_phase21a');
    $pdo->exec('DROP TRIGGER IF EXISTS trg_ctrl_countries_bu_row_version');
    $pdo->exec(<<<'SQL'
CREATE TRIGGER trg_ctrl_countries_bu_phase21a
BEFORE UPDATE ON ctrl_countries
FOR EACH ROW
BEGIN
    IF NEW.lifecycle_status = 'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'phase21c_prerequisites_not_available';
    END IF;
    IF NEW.row_version <> OLD.row_version THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'country_row_version_direct_update_denied';
    END IF;
    SET NEW.row_version = OLD.row_version + 1;
    SET NEW.updated_at = NOW(6);
END
SQL);
}
function orange_control_domain_install_tables(PDO $pdo): void {
    foreach (array_filter(array_map('trim', explode(';', orange_control_domain_nine_tables_sql()))) as $stmt) {
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        $pdo->exec($stmt);
    }
    orange_control_domain_apply_countries_k01($pdo);
    orange_control_domain_install_country_trigger($pdo);
}

function orange_control_domain_install_routines(PDO $pdo): void {
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_registry_upsert`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_registry_upsert`(
  IN p_session_token_hash CHAR(64), IN p_domain_uuid CHAR(36), IN p_environment VARCHAR(16), IN p_https_policy VARCHAR(32), IN p_display_label VARCHAR(191), IN p_notes_utf8 VARCHAR(512), IN p_expected_row_version INT, OUT p_registry_id BIGINT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_id BIGINT UNSIGNED; DECLARE v_rv INT UNSIGNED; DECLARE v_status VARCHAR(16);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.registry.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF p_environment NOT IN (\'local\',\'staging\',\'production\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_ENV\'; END IF;
IF p_https_policy NOT IN (\'force_https\',\'allow_http_local\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_HTTPS\'; END IF;
SELECT id, row_version, registry_status INTO v_id, v_rv, v_status FROM ctrl_domain_registry WHERE domain_uuid=p_domain_uuid LIMIT 1 FOR UPDATE;
IF v_id IS NULL THEN
  IF p_expected_row_version <> 0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CREATE_EXPECTED_ZERO\'; END IF;
  INSERT INTO ctrl_domain_registry(domain_uuid,environment,https_policy,registry_status,display_label,notes_utf8,created_by_admin_id,updated_by_admin_id,row_version,created_at,updated_at)
  VALUES(p_domain_uuid,p_environment,p_https_policy,\'draft\',p_display_label,p_notes_utf8,v_admin_id,v_admin_id,1,NOW(6),NOW(6));
  SET p_registry_id = LAST_INSERT_ID(); SET p_row_version_after = 1;
ELSE
  IF v_status <> \'draft\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_UPSERT_DRAFT_ONLY\'; END IF;
  IF v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  UPDATE ctrl_domain_registry SET https_policy=p_https_policy, display_label=p_display_label, notes_utf8=p_notes_utf8,
    updated_by_admin_id=v_admin_id, row_version=v_rv+1, updated_at=NOW(6) WHERE id=v_id AND row_version=v_rv;
  IF ROW_COUNT()=0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  SET p_registry_id = v_id; SET p_row_version_after = v_rv+1;
END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_registry_upserted\',\'ctrl_domain_registry\',p_registry_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_registry_upsert\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_registry_upserted\',\'ctrl_domain_registry\',p_registry_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_registry_upsert\'),NOW(6));
END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_registry_status_transition`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_registry_status_transition`(
  IN p_session_token_hash CHAR(64), IN p_registry_id BIGINT, IN p_to_status VARCHAR(16), IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_from VARCHAR(16);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.registry.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT row_version, registry_status INTO v_rv, v_from FROM ctrl_domain_registry WHERE id=p_registry_id FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
IF NOT ((v_from=\'draft\' AND p_to_status IN (\'pending_verification\',\'revoked\')) OR (v_from=\'pending_verification\' AND p_to_status IN (\'verified\',\'draft\',\'revoked\')) OR (v_from=\'verified\' AND p_to_status IN (\'active\',\'retired\',\'revoked\')) OR (v_from=\'active\' AND p_to_status IN (\'retired\',\'revoked\')) OR (v_from=\'retired\' AND p_to_status=\'revoked\')) THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_TRANSITION\'; END IF;
UPDATE ctrl_domain_registry SET registry_status=p_to_status, row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_registry_id AND row_version=v_rv;
SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_registry_status_changed\',\'ctrl_domain_registry\',p_registry_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_registry_status_transition\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_registry_status_changed\',\'ctrl_domain_registry\',p_registry_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_registry_status_transition\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_host_upsert`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_host_upsert`(
  IN p_session_token_hash CHAR(64), IN p_host_uuid CHAR(36), IN p_registry_id BIGINT, IN p_normalized_host VARCHAR(253), IN p_host_role VARCHAR(16), IN p_redirect_policy VARCHAR(16), IN p_expected_row_version INT, OUT p_host_id BIGINT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_env VARCHAR(16); DECLARE v_id BIGINT UNSIGNED; DECLARE v_rv INT UNSIGNED; DECLARE v_status VARCHAR(16);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.host.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT environment INTO v_env FROM ctrl_domain_registry WHERE id=p_registry_id FOR UPDATE;
IF v_env IS NULL THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_REGISTRY\'; END IF;
IF p_host_role NOT IN (\'canonical\',\'alias\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_ROLE\'; END IF;
IF p_redirect_policy NOT IN (\'none\',\'temporary_302\',\'permanent_308\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_REDIRECT\'; END IF;
SELECT id,row_version,host_status INTO v_id,v_rv,v_status FROM ctrl_domain_hosts WHERE host_uuid=p_host_uuid LIMIT 1 FOR UPDATE;
IF v_id IS NULL THEN
  IF p_expected_row_version<>0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CREATE_EXPECTED_ZERO\'; END IF;
  INSERT INTO ctrl_domain_hosts(host_uuid,registry_id,environment,normalized_host,host_role,host_status,redirect_policy,verification_method,health_status,created_by_admin_id,updated_by_admin_id,row_version,created_at,updated_at)
  VALUES(p_host_uuid,p_registry_id,v_env,LOWER(TRIM(p_normalized_host)),p_host_role,\'draft\',p_redirect_policy,\'dns_txt\',\'unknown\',v_admin_id,v_admin_id,1,NOW(6),NOW(6));
  SET p_host_id=LAST_INSERT_ID(); SET p_row_version_after=1;
ELSE
  IF v_status<>\'draft\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_UPSERT_DRAFT_ONLY\'; END IF;
  IF v_rv<>p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  UPDATE ctrl_domain_hosts SET normalized_host=LOWER(TRIM(p_normalized_host)), host_role=p_host_role, redirect_policy=p_redirect_policy, updated_by_admin_id=v_admin_id, row_version=v_rv+1, updated_at=NOW(6) WHERE id=v_id AND row_version=v_rv;
  SET p_host_id=v_id; SET p_row_version_after=v_rv+1;
END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_host_upserted\',\'ctrl_domain_hosts\',p_host_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_host_upsert\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_host_upserted\',\'ctrl_domain_hosts\',p_host_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_host_upsert\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_host_status_transition`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_host_status_transition`(
  IN p_session_token_hash CHAR(64), IN p_host_id BIGINT, IN p_to_status VARCHAR(16), IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_from VARCHAR(16);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.host.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT row_version, host_status INTO v_rv, v_from FROM ctrl_domain_hosts WHERE id=p_host_id FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
IF NOT ((v_from=\'draft\' AND p_to_status IN (\'pending_verification\',\'revoked\')) OR (v_from=\'pending_verification\' AND p_to_status IN (\'verified\',\'draft\',\'revoked\')) OR (v_from=\'verified\' AND p_to_status IN (\'active\',\'retired\',\'revoked\')) OR (v_from=\'active\' AND p_to_status IN (\'suspended\',\'retired\',\'revoked\')) OR (v_from=\'suspended\' AND p_to_status IN (\'active\',\'retired\',\'revoked\')) OR (v_from=\'retired\' AND p_to_status=\'revoked\')) THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_TRANSITION\'; END IF;
UPDATE ctrl_domain_hosts SET host_status=p_to_status, activated_at=IF(p_to_status=\'active\',IFNULL(activated_at,NOW(6)),activated_at), suspended_at=IF(p_to_status=\'suspended\',NOW(6),suspended_at), row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_host_id AND row_version=v_rv;
SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_host_status_changed\',\'ctrl_domain_hosts\',p_host_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_host_status_transition\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_host_status_changed\',\'ctrl_domain_hosts\',p_host_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_host_status_transition\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_host_health_record`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_host_health_record`(
  IN p_session_token_hash CHAR(64), IN p_host_id BIGINT, IN p_health_status VARCHAR(16), IN p_health_evidence_hash BINARY(32), IN p_expected_row_version INT, OUT p_ok TINYINT, OUT p_failure_code VARCHAR(64), OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED;
main: BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN SET p_ok=0; RESIGNAL; END;
SET p_ok=0; SET p_failure_code=NULL; SET p_row_version_after=NULL;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.health.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF p_health_status NOT IN (\'unknown\',\'pending\',\'healthy\',\'unhealthy\') OR p_health_evidence_hash IS NULL THEN SET p_failure_code=\'DOMAIN_E_HEALTH_REQUIRED\'; LEAVE main; END IF;
SELECT row_version INTO v_rv FROM ctrl_domain_hosts WHERE id=p_host_id FOR UPDATE;
IF v_rv IS NULL THEN SET p_failure_code=\'DNS_E_HOST_NOT_FOUND\'; LEAVE main; END IF;
IF v_rv <> p_expected_row_version THEN SET p_failure_code=\'DNS_E_CAS_CONFLICT\'; LEAVE main; END IF;
UPDATE ctrl_domain_hosts SET health_status=p_health_status, health_evidence_hash=p_health_evidence_hash, last_health_at=NOW(6), row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_host_id AND row_version=v_rv;
SET p_ok=1; SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_host_health_recorded\',\'ctrl_domain_hosts\',p_host_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_host_health_record\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_host_health_recorded\',\'ctrl_domain_hosts\',p_host_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_host_health_record\'),NOW(6));
END main;

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_authority_set_mode`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_authority_set_mode`(
  IN p_session_token_hash CHAR(64), IN p_environment VARCHAR(16), IN p_mode VARCHAR(32), IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED;
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.cutover\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF p_mode NOT IN (\'env_only\',\'registry_prefer\',\'registry_strict\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'DOMAIN_E_MODE\'; END IF;
SELECT row_version INTO v_rv FROM ctrl_domain_authority WHERE environment=p_environment FOR UPDATE;
IF v_rv IS NULL THEN
  IF p_expected_row_version<>0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CREATE_EXPECTED_ZERO\'; END IF;
  INSERT INTO ctrl_domain_authority(environment,host_authority_mode,created_at,created_by_admin_id,updated_at,updated_by_admin_id,row_version)
  VALUES(p_environment,p_mode,NOW(6),v_admin_id,NOW(6),v_admin_id,1);
  SET p_row_version_after=1;
ELSE
  IF v_rv<>p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  UPDATE ctrl_domain_authority SET host_authority_mode=p_mode, updated_at=NOW(6), updated_by_admin_id=v_admin_id, row_version=v_rv+1 WHERE environment=p_environment AND row_version=v_rv;
  SET p_row_version_after=v_rv+1;
END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_authority_mode_changed\',\'ctrl_domain_authority\',NULL,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_authority_set_mode\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_authority_mode_changed\',\'ctrl_domain_authority\',NULL,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_authority_set_mode\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_cutover_cas_begin`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_cutover_cas_begin`(
  IN p_session_token_hash CHAR(64), IN p_environment VARCHAR(16), IN p_lock_token_hash BINARY(32), IN p_lock_ttl_seconds INT, IN p_expected_authority_row_version INT, OUT p_ok TINYINT, OUT p_failure_code VARCHAR(64), OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_tok BINARY(32); DECLARE v_exp DATETIME(6);
main: BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN SET p_ok=0; RESIGNAL; END;
SET p_ok=0; SET p_failure_code=NULL; SET p_row_version_after=NULL;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.cutover\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF p_lock_token_hash IS NULL OR p_lock_ttl_seconds < 1 THEN SET p_failure_code=\'DOMAIN_E_LOCK\'; LEAVE main; END IF;
START TRANSACTION;
SELECT row_version, cutover_lock_token_hash, cutover_lock_expires_at INTO v_rv, v_tok, v_exp FROM ctrl_domain_authority WHERE environment=p_environment FOR UPDATE;
IF v_rv IS NULL THEN ROLLBACK; SET p_failure_code=\'DOMAIN_E_AUTHORITY\'; LEAVE main; END IF;
IF v_rv <> p_expected_authority_row_version THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
IF v_tok IS NOT NULL AND v_exp IS NOT NULL AND v_exp > NOW(6) THEN ROLLBACK; SET p_failure_code=\'DOMAIN_E_LOCK_HELD\'; LEAVE main; END IF;
UPDATE ctrl_domain_authority SET cutover_lock_token_hash=p_lock_token_hash, cutover_lock_expires_at=TIMESTAMPADD(SECOND,p_lock_ttl_seconds,NOW(6)), updated_at=NOW(6), updated_by_admin_id=v_admin_id, row_version=v_rv+1 WHERE environment=p_environment AND row_version=v_rv;
IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_cutover_cas_begin\',\'ctrl_domain_authority\',NULL,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_cutover_cas_begin\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_cutover_cas_begin\',\'ctrl_domain_authority\',NULL,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_cutover_cas_begin\'),NOW(6));
COMMIT;
SET p_ok=1; SET p_row_version_after=v_rv+1;
END main;

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_cutover_cas_commit`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_cutover_cas_commit`(
  IN p_session_token_hash CHAR(64), IN p_environment VARCHAR(16), IN p_lock_token_hash BINARY(32), IN p_target_host_id BIGINT, IN p_monitoring_seconds INT, IN p_expected_authority_row_version INT, IN p_expected_target_host_row_version INT, IN p_expected_previous_host_row_version INT, IN p_expected_registry_row_version INT, OUT p_ok TINYINT, OUT p_failure_code VARCHAR(64), OUT p_authority_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_arv INT UNSIGNED; DECLARE v_tok BINARY(32); DECLARE v_exp DATETIME(6); DECLARE v_prev BIGINT UNSIGNED; DECLARE v_active BIGINT UNSIGNED; DECLARE v_trv INT UNSIGNED; DECLARE v_tstatus VARCHAR(16); DECLARE v_tenv VARCHAR(16); DECLARE v_health DATETIME(6); DECLARE v_hstat VARCHAR(16); DECLARE v_mon INT; DECLARE v_reg BIGINT UNSIGNED; DECLARE v_rrv INT UNSIGNED; DECLARE v_prv INT UNSIGNED;
main: BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; SET p_ok=0; RESIGNAL; END;
SET p_ok=0; SET p_failure_code=NULL; SET p_authority_row_version_after=NULL;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.cutover\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SET v_mon = IFNULL(p_monitoring_seconds,300); IF v_mon < 300 THEN SET v_mon=300; END IF; IF v_mon > 604800 THEN SET v_mon=604800; END IF;
START TRANSACTION;
/* lock order: authority -> previous(if any) -> target -> registry(validate-only) */
SELECT row_version, cutover_lock_token_hash, cutover_lock_expires_at, previous_canonical_host_id, active_canonical_host_id INTO v_arv, v_tok, v_exp, v_prev, v_active FROM ctrl_domain_authority WHERE environment=p_environment FOR UPDATE;
IF v_arv IS NULL OR v_arv <> p_expected_authority_row_version OR v_tok IS NULL OR v_tok <> p_lock_token_hash OR v_exp IS NULL OR v_exp <= NOW(6) THEN ROLLBACK; SET p_failure_code=\'DOMAIN_E_LOCK\'; LEAVE main; END IF;
SET v_prv = NULL;
IF v_active IS NOT NULL THEN
  SELECT row_version INTO v_prv FROM ctrl_domain_hosts WHERE id=v_active FOR UPDATE;
  IF v_prv IS NULL OR v_prv <> p_expected_previous_host_row_version THEN ROLLBACK; SET p_failure_code=\'CAS_E_PREVIOUS_HOST\'; LEAVE main; END IF;
ELSE
  /* first-activation: previous host NULL requires expected previous version = 0 */
  IF p_expected_previous_host_row_version <> 0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_PREVIOUS_HOST_FIRST\'; LEAVE main; END IF;
END IF;
SELECT row_version, host_status, environment, last_health_at, health_status, registry_id INTO v_trv, v_tstatus, v_tenv, v_health, v_hstat, v_reg FROM ctrl_domain_hosts WHERE id=p_target_host_id FOR UPDATE;
IF v_trv IS NULL OR v_trv <> p_expected_target_host_row_version OR v_tstatus <> \'verified\' OR v_tenv <> p_environment THEN ROLLBACK; SET p_failure_code=\'DOMAIN_E_TARGET\'; LEAVE main; END IF;
IF v_health IS NULL OR v_hstat IS NULL OR v_hstat <> \'healthy\' OR TIMESTAMPDIFF(SECOND,v_health,NOW(6)) > 600 THEN ROLLBACK; SET p_failure_code=\'DOMAIN_E_HEALTH_REQUIRED\'; LEAVE main; END IF;
SELECT row_version INTO v_rrv FROM ctrl_domain_registry WHERE id=v_reg FOR UPDATE;
IF v_rrv IS NULL OR v_rrv <> p_expected_registry_row_version THEN ROLLBACK; SET p_failure_code=\'CAS_E_REGISTRY\'; LEAVE main; END IF;
/* registry validate-only: no registry_status / row_version mutation */
IF v_active IS NOT NULL THEN
  UPDATE ctrl_domain_hosts SET host_role=\'alias\', host_status=\'active\', redirect_policy=\'temporary_302\', row_version=v_prv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=v_active AND row_version=v_prv;
  IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
END IF;
UPDATE ctrl_domain_hosts SET host_role=\'canonical\', host_status=\'active\', redirect_policy=\'none\', activated_at=IFNULL(activated_at,NOW(6)), row_version=v_trv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_target_host_id AND row_version=v_trv;
IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
UPDATE ctrl_domain_authority SET previous_canonical_host_id=v_active, active_canonical_host_id=p_target_host_id, monitoring_until=TIMESTAMPADD(SECOND,v_mon,NOW(6)), cutover_lock_token_hash=NULL, cutover_lock_expires_at=NULL, last_cutover_at=NOW(6), last_cutover_by_admin_id=v_admin_id, updated_at=NOW(6), updated_by_admin_id=v_admin_id, row_version=v_arv+1 WHERE environment=p_environment AND row_version=v_arv;
IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_cutover_cas_commit\',\'ctrl_domain_hosts\',p_target_host_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_cutover_cas_commit\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_cutover_cas_commit\',\'ctrl_domain_hosts\',p_target_host_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_cutover_cas_commit\'),NOW(6));
COMMIT;
SET p_ok=1; SET p_authority_row_version_after=v_arv+1;
END main;

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_cutover_cas_abort`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_cutover_cas_abort`(
  IN p_session_token_hash CHAR(64), IN p_environment VARCHAR(16), IN p_lock_token_hash BINARY(32), IN p_expected_authority_row_version INT, OUT p_ok TINYINT, OUT p_failure_code VARCHAR(64), OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_tok BINARY(32);
main: BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; SET p_ok=0; RESIGNAL; END;
SET p_ok=0; SET p_failure_code=NULL; SET p_row_version_after=NULL;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.cutover\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
START TRANSACTION;
SELECT row_version, cutover_lock_token_hash INTO v_rv, v_tok FROM ctrl_domain_authority WHERE environment=p_environment FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_authority_row_version OR v_tok IS NULL OR v_tok <> p_lock_token_hash THEN ROLLBACK; SET p_failure_code=\'DOMAIN_E_LOCK\'; LEAVE main; END IF;
UPDATE ctrl_domain_authority SET cutover_lock_token_hash=NULL, cutover_lock_expires_at=NULL, updated_at=NOW(6), updated_by_admin_id=v_admin_id, row_version=v_rv+1 WHERE environment=p_environment AND row_version=v_rv;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_cutover_cas_abort\',\'ctrl_domain_authority\',NULL,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_cutover_cas_abort\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_cutover_cas_abort\',\'ctrl_domain_authority\',NULL,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_cutover_cas_abort\'),NOW(6));
COMMIT;
SET p_ok=1; SET p_row_version_after=v_rv+1;
END main;

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_dns_challenge_issue`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_dns_challenge_issue`(
  IN p_session_token_hash CHAR(64), IN p_host_id BIGINT, IN p_token_hash BINARY(32), IN p_expected_row_version INT, OUT p_ok TINYINT, OUT p_failure_code VARCHAR(64), OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_status VARCHAR(16);
main: BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN SET p_ok=0; RESIGNAL; END;
SET p_ok=0; SET p_failure_code=NULL; SET p_row_version_after=NULL;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.dns.verify\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT row_version, host_status INTO v_rv, v_status FROM ctrl_domain_hosts WHERE id=p_host_id FOR UPDATE;
IF v_rv IS NULL THEN SET p_failure_code=\'DNS_E_HOST_NOT_FOUND\'; LEAVE main; END IF;
IF v_rv <> p_expected_row_version THEN SET p_failure_code=\'DNS_E_CAS_CONFLICT\'; LEAVE main; END IF;
IF p_token_hash IS NULL THEN SET p_failure_code=\'DNS_E_TOKEN\'; LEAVE main; END IF;
UPDATE ctrl_domain_hosts SET verification_method=\'dns_txt\', verification_token_hash=p_token_hash, challenge_expires_at=TIMESTAMPADD(SECOND,86400,NOW(6)), verification_attempt_count=0, host_status=IF(v_status=\'draft\',\'pending_verification\',v_status), row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_host_id AND row_version=v_rv;
SET p_ok=1; SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_dns_challenge_issued\',\'ctrl_domain_hosts\',p_host_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_dns_challenge_issue\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_dns_challenge_issued\',\'ctrl_domain_hosts\',p_host_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_dns_challenge_issue\'),NOW(6));
END main;

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_dns_challenge_verify`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_dns_challenge_verify`(
  IN p_session_token_hash CHAR(64), IN p_host_id BIGINT, IN p_token_hash BINARY(32), IN p_expected_row_version INT, OUT p_ok TINYINT, OUT p_failure_code VARCHAR(64), OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_hash BINARY(32); DECLARE v_exp DATETIME(6); DECLARE v_attempts INT UNSIGNED;
main: BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; SET p_ok=0; RESIGNAL; END;
SET p_ok=0; SET p_failure_code=NULL; SET p_row_version_after=NULL;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.dns.verify\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
START TRANSACTION;
SELECT row_version, verification_token_hash, challenge_expires_at, verification_attempt_count INTO v_rv, v_hash, v_exp, v_attempts FROM ctrl_domain_hosts WHERE id=p_host_id FOR UPDATE;
IF v_rv IS NULL THEN ROLLBACK; SET p_failure_code=\'DNS_E_HOST_NOT_FOUND\'; LEAVE main; END IF;
IF v_rv <> p_expected_row_version THEN ROLLBACK; SET p_failure_code=\'DNS_E_CAS_CONFLICT\'; LEAVE main; END IF;
IF v_exp IS NULL OR v_exp <= NOW(6) THEN ROLLBACK; SET p_failure_code=\'DNS_E_EXPIRED\'; LEAVE main; END IF;
IF v_attempts >= 10 THEN
  UPDATE ctrl_domain_hosts SET verification_attempt_count=v_attempts, row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_host_id AND row_version=v_rv;
  IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'DNS_E_CAS_CONFLICT\'; LEAVE main; END IF;
  INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_dns_challenge_mismatch_max\',\'ctrl_domain_hosts\',p_host_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'attempts\',v_attempts,\'max\',1),NOW(6));
  INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_dns_challenge_mismatch_max\',\'ctrl_domain_hosts\',p_host_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'attempts\',v_attempts,\'max\',1),NOW(6));
  COMMIT; SET p_ok=0; SET p_failure_code=\'DNS_E_MAX_ATTEMPTS\'; SET p_row_version_after=v_rv+1; LEAVE main;
END IF;
IF v_hash IS NOT NULL AND p_token_hash IS NOT NULL AND v_hash = p_token_hash THEN
  UPDATE ctrl_domain_hosts SET host_status=\'verified\', verified_at=NOW(6), verification_token_hash=NULL, row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_host_id AND row_version=v_rv;
  IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'DNS_E_CAS_CONFLICT\'; LEAVE main; END IF;
  INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_dns_challenge_verified\',\'ctrl_domain_hosts\',p_host_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'ok\',1),NOW(6));
  INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_dns_challenge_verified\',\'ctrl_domain_hosts\',p_host_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'ok\',1),NOW(6));
  COMMIT; SET p_ok=1; SET p_row_version_after=v_rv+1; LEAVE main;
END IF;
/* controlled mismatch persist */
UPDATE ctrl_domain_hosts SET verification_attempt_count=v_attempts+1, row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_host_id AND row_version=v_rv;
IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'DNS_E_CAS_CONFLICT\'; LEAVE main; END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_dns_challenge_mismatch\',\'ctrl_domain_hosts\',p_host_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'attempts\',v_attempts+1),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_dns_challenge_mismatch\',\'ctrl_domain_hosts\',p_host_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'attempts\',v_attempts+1),NOW(6));
COMMIT;
SET p_ok=0; SET p_failure_code=\'DNS_E_HASH_MISMATCH\'; SET p_row_version_after=v_rv+1;
END main;

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_redirect_permanent_approve`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_redirect_permanent_approve`(
  IN p_session_token_hash CHAR(64), IN p_environment VARCHAR(16), IN p_host_id BIGINT, IN p_expected_authority_row_version INT, IN p_expected_host_row_version INT, OUT p_ok TINYINT, OUT p_failure_code VARCHAR(64), OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_hrv INT UNSIGNED; DECLARE v_arv INT UNSIGNED; DECLARE v_env VARCHAR(16); DECLARE v_mon DATETIME(6);
main: BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; SET p_ok=0; RESIGNAL; END;
SET p_ok=0; SET p_failure_code=NULL; SET p_row_version_after=NULL;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.redirect.approve\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
START TRANSACTION;
SELECT row_version, environment INTO v_hrv, v_env FROM ctrl_domain_hosts WHERE id=p_host_id FOR UPDATE;
IF v_hrv IS NULL OR v_hrv <> p_expected_host_row_version THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
IF v_env IS NULL OR v_env <> p_environment THEN ROLLBACK; SET p_failure_code=\'DOMAIN_E_ENV\'; LEAVE main; END IF;
SELECT row_version, monitoring_until INTO v_arv, v_mon FROM ctrl_domain_authority WHERE environment=p_environment FOR UPDATE;
IF v_arv IS NULL OR v_arv <> p_expected_authority_row_version THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
IF v_mon IS NULL OR NOW(6) < v_mon THEN ROLLBACK; SET p_failure_code=\'DOMAIN_E_MONITORING\'; LEAVE main; END IF;
UPDATE ctrl_domain_hosts SET redirect_policy=\'permanent_308\', permanent_redirect_approved_at=NOW(6), permanent_redirect_approved_by_admin_id=v_admin_id, row_version=v_hrv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_host_id AND row_version=v_hrv;
IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
UPDATE ctrl_domain_authority SET updated_at=NOW(6), updated_by_admin_id=v_admin_id, row_version=v_arv+1 WHERE environment=p_environment AND row_version=v_arv;
IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_redirect_permanent_approved\',\'ctrl_domain_hosts\',p_host_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_redirect_permanent_approve\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_redirect_permanent_approved\',\'ctrl_domain_hosts\',p_host_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_redirect_permanent_approve\'),NOW(6));
COMMIT;
SET p_ok=1; SET p_row_version_after=v_hrv+1;
END main;

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_domain_emergency_rollback`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_domain_emergency_rollback`(
  IN p_session_token_hash CHAR(64), IN p_environment VARCHAR(16), IN p_expected_authority_row_version INT, IN p_expected_failed_host_row_version INT, IN p_expected_previous_host_row_version INT, OUT p_ok TINYINT, OUT p_failure_code VARCHAR(64), OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_arv INT UNSIGNED; DECLARE v_active BIGINT UNSIGNED; DECLARE v_prev BIGINT UNSIGNED; DECLARE v_frv INT UNSIGNED; DECLARE v_prv INT UNSIGNED;
main: BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; SET p_ok=0; RESIGNAL; END;
SET p_ok=0; SET p_failure_code=NULL; SET p_row_version_after=NULL;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.domain.rollback\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
START TRANSACTION;
SELECT row_version, active_canonical_host_id, previous_canonical_host_id INTO v_arv, v_active, v_prev FROM ctrl_domain_authority WHERE environment=p_environment FOR UPDATE;
IF v_arv IS NULL OR v_arv <> p_expected_authority_row_version OR v_prev IS NULL THEN ROLLBACK; SET p_failure_code=\'DOMAIN_E_ROLLBACK\'; LEAVE main; END IF;
SELECT row_version INTO v_prv FROM ctrl_domain_hosts WHERE id=v_prev FOR UPDATE;
IF v_prv IS NULL OR v_prv <> p_expected_previous_host_row_version THEN ROLLBACK; SET p_failure_code=\'CAS_E_PREVIOUS_HOST\'; LEAVE main; END IF;
SET v_frv = NULL;
IF v_active IS NOT NULL THEN
  SELECT row_version INTO v_frv FROM ctrl_domain_hosts WHERE id=v_active FOR UPDATE;
  IF v_frv IS NULL OR v_frv <> p_expected_failed_host_row_version THEN ROLLBACK; SET p_failure_code=\'CAS_E_FAILED_HOST\'; LEAVE main; END IF;
ELSE
  IF p_expected_failed_host_row_version <> 0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_FAILED_HOST\'; LEAVE main; END IF;
END IF;
UPDATE ctrl_domain_hosts SET host_role=\'canonical\', host_status=\'active\', redirect_policy=\'none\', row_version=v_prv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=v_prev AND row_version=v_prv;
IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
IF v_active IS NOT NULL THEN
  UPDATE ctrl_domain_hosts SET host_role=\'alias\', host_status=\'active\', redirect_policy=\'temporary_302\', row_version=v_frv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=v_active AND row_version=v_frv;
  IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
END IF;
UPDATE ctrl_domain_authority SET active_canonical_host_id=v_prev, previous_canonical_host_id=v_active, emergency_env_rollback_at=NOW(6), emergency_env_rollback_by_admin_id=v_admin_id, cutover_lock_token_hash=NULL, cutover_lock_expires_at=NULL, updated_at=NOW(6), updated_by_admin_id=v_admin_id, row_version=v_arv+1 WHERE environment=p_environment AND row_version=v_arv;
IF ROW_COUNT()=0 THEN ROLLBACK; SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_emergency_env_rollback\',\'ctrl_domain_hosts\',v_prev,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_emergency_rollback\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'domain_emergency_env_rollback\',\'ctrl_domain_hosts\',v_prev,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_domain_emergency_rollback\'),NOW(6));
COMMIT;
SET p_ok=1; SET p_row_version_after=v_arv+1;
END main;

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_country_geo_flag_set`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_country_geo_flag_set`(
  IN p_session_token_hash CHAR(64), IN p_country_id INT UNSIGNED, IN p_geo_iso2_code CHAR(2), IN p_flag_emoji VARCHAR(16), IN p_expected_row_version INT, OUT p_ok TINYINT, OUT p_failure_code VARCHAR(64), OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_iso CHAR(2); DECLARE v_flag VARCHAR(16);
main: BEGIN
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN SET p_ok=0; RESIGNAL; END;
SET p_ok=0; SET p_failure_code=NULL; SET p_row_version_after=NULL;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.country.geo.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SET v_iso = NULLIF(UPPER(TRIM(p_geo_iso2_code)),\'\');
IF v_iso IS NOT NULL AND v_iso NOT REGEXP \'^[A-Z]{2}$\' THEN SET p_failure_code=\'COUNTRY_E_ISO2\'; LEAVE main; END IF;
SET v_flag = NULLIF(p_flag_emoji,\'\');
IF v_flag IS NOT NULL THEN
  IF CHAR_LENGTH(v_flag) < 2 OR CHAR_LENGTH(v_flag) > 2 THEN SET p_failure_code=\'COUNTRY_E_FLAG\'; LEAVE main; END IF;
  SET @f1 = CONV(HEX(CONVERT(SUBSTRING(v_flag,1,1) USING utf32)),16,10);
  SET @f2 = CONV(HEX(CONVERT(SUBSTRING(v_flag,2,1) USING utf32)),16,10);
  IF @f1 < 0x1F1E6 OR @f1 > 0x1F1FF OR @f2 < 0x1F1E6 OR @f2 > 0x1F1FF THEN SET p_failure_code=\'COUNTRY_E_FLAG\'; LEAVE main; END IF;
END IF;
SELECT row_version INTO v_rv FROM ctrl_countries WHERE id=p_country_id FOR UPDATE;
IF v_rv IS NULL THEN SET p_failure_code=\'COUNTRY_E_NOT_FOUND\'; LEAVE main; END IF;
IF v_rv <> p_expected_row_version THEN SET p_failure_code=\'CAS_E_CONFLICT\'; LEAVE main; END IF;
UPDATE ctrl_countries SET geo_iso2_code=v_iso, flag_emoji=v_flag, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_country_id;
/* trigger owns row_version bump */
SELECT row_version INTO p_row_version_after FROM ctrl_countries WHERE id=p_country_id;
SET p_ok=1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'country_geo_iso2_set\',\'ctrl_countries\',p_country_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_country_geo_flag_set\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'country_geo_iso2_set\',\'ctrl_countries\',p_country_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_country_geo_flag_set\'),NOW(6));
END main;

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_public_channel_route_upsert`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_public_channel_route_upsert`(
  IN p_session_token_hash CHAR(64), IN p_route_uuid CHAR(36), IN p_country_id INT UNSIGNED, IN p_match_kind VARCHAR(16), IN p_match_value VARCHAR(64), IN p_canonical_channel_slug VARCHAR(64), IN p_source_channel_id_snapshot INT, IN p_redirect_policy VARCHAR(16), IN p_notes_utf8 VARCHAR(512), IN p_expected_row_version INT, OUT p_route_id BIGINT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_id BIGINT UNSIGNED; DECLARE v_rv INT UNSIGNED; DECLARE v_code VARCHAR(8); DECLARE v_mv VARCHAR(64); DECLARE v_ccnt INT UNSIGNED;
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.channel.route.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT code INTO v_code FROM ctrl_countries WHERE id=p_country_id;
IF v_code IS NULL THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'ROUTE_E_COUNTRY\'; END IF;
IF p_match_kind NOT IN (\'path_segment\',\'query_channel\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'ROUTE_E_MATCH_KIND\'; END IF;
IF p_redirect_policy NOT IN (\'none\',\'temporary_302\',\'permanent_308\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'ROUTE_E_REDIRECT\'; END IF;
SET v_mv = LOWER(TRIM(p_match_value));
IF p_match_kind=\'path_segment\' THEN
  SELECT COUNT(*) INTO v_ccnt FROM ctrl_countries WHERE LOWER(code)=v_mv;
  IF v_ccnt > 0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'ROUTE_E_RECLAIM_COUNTRY_PATH\'; END IF;
  IF v_mv IN (\'admin\',\'api\',\'assets\',\'cart\',\'checkout\',\'login\',\'logout\',\'account\',\'track\',\'health\',\'c\',\'static\',\'uploads\',\'webhook\',\'webhooks\',\'favicon.ico\',\'robots.txt\',\'sitemap.xml\',\'privacy\',\'terms\',\'search\',\'product\',\'products\',\'page\',\'pages\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'ROUTE_E_RESERVED\'; END IF;
END IF;
SELECT id,row_version INTO v_id,v_rv FROM ctrl_public_channel_route_registry WHERE route_uuid=p_route_uuid LIMIT 1 FOR UPDATE;
IF v_id IS NULL THEN
  IF p_expected_row_version<>0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CREATE_EXPECTED_ZERO\'; END IF;
  INSERT INTO ctrl_public_channel_route_registry(route_uuid,country_id,country_code_snapshot,match_kind,match_value,canonical_channel_slug,source_channel_id_snapshot,route_status,redirect_policy,notes_utf8,created_by_admin_id,updated_by_admin_id,row_version,created_at,updated_at)
  VALUES(p_route_uuid,p_country_id,v_code,p_match_kind,v_mv,LOWER(TRIM(p_canonical_channel_slug)),p_source_channel_id_snapshot,\'dormant\',p_redirect_policy,p_notes_utf8,v_admin_id,v_admin_id,1,NOW(6),NOW(6));
  SET p_route_id=LAST_INSERT_ID(); SET p_row_version_after=1;
ELSE
  IF v_rv<>p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  UPDATE ctrl_public_channel_route_registry SET match_kind=p_match_kind, match_value=v_mv, canonical_channel_slug=LOWER(TRIM(p_canonical_channel_slug)), source_channel_id_snapshot=p_source_channel_id_snapshot, redirect_policy=p_redirect_policy, notes_utf8=p_notes_utf8, updated_by_admin_id=v_admin_id, row_version=v_rv+1, updated_at=NOW(6) WHERE id=v_id AND row_version=v_rv;
  IF ROW_COUNT()=0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  SET p_route_id=v_id; SET p_row_version_after=v_rv+1;
END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'public_channel_route_upserted\',\'ctrl_public_channel_route_registry\',p_route_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_public_channel_route_upsert\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'public_channel_route_upserted\',\'ctrl_public_channel_route_registry\',p_route_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_public_channel_route_upsert\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_public_channel_route_status_transition`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_public_channel_route_status_transition`(
  IN p_session_token_hash CHAR(64), IN p_route_id BIGINT, IN p_to_status VARCHAR(16), IN p_expected_row_version INT, OUT p_ok TINYINT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_from VARCHAR(16); DECLARE v_snap INT;
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.channel.route.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT row_version, route_status, source_channel_id_snapshot INTO v_rv, v_from, v_snap FROM ctrl_public_channel_route_registry WHERE id=p_route_id FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
IF p_to_status=\'active_compat\' AND v_snap IS NULL THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'ROUTE_E_SNAPSHOT_REQUIRED\'; END IF;
IF NOT ((v_from=\'dormant\' AND p_to_status IN (\'active_compat\',\'revoked\')) OR (v_from=\'active_compat\' AND p_to_status IN (\'retired\',\'revoked\')) OR (v_from=\'retired\' AND p_to_status=\'revoked\')) THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'ROUTE_E_TRANSITION\'; END IF;
UPDATE ctrl_public_channel_route_registry SET route_status=p_to_status, retired_at=IF(p_to_status=\'retired\',NOW(6),retired_at), retired_by_admin_id=IF(p_to_status=\'retired\',v_admin_id,retired_by_admin_id), row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_route_id AND row_version=v_rv;
SET p_ok=1; SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'public_channel_route_status_changed\',\'ctrl_public_channel_route_registry\',p_route_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_public_channel_route_status_transition\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'public_channel_route_status_changed\',\'ctrl_public_channel_route_registry\',p_route_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_public_channel_route_status_transition\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_market_entry_policy_upsert`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_market_entry_policy_upsert`(
  IN p_session_token_hash CHAR(64), IN p_country_id INT UNSIGNED, IN p_unavailable_message_mode VARCHAR(8), IN p_market_content_id BIGINT, IN p_allow_global_wa_fallback TINYINT, IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED;
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.market.policy.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF p_unavailable_message_mode NOT IN (\'AUTO\',\'SHOW\',\'HIDE\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'MARKET_E_MODE\'; END IF;
SELECT row_version INTO v_rv FROM ctrl_market_entry_policy WHERE country_id=p_country_id FOR UPDATE;
IF v_rv IS NULL THEN
  IF p_expected_row_version<>0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CREATE_EXPECTED_ZERO\'; END IF;
  INSERT INTO ctrl_market_entry_policy(country_id,market_status,unavailable_message_mode,market_content_id,allow_global_wa_fallback,international_shipping_mode,created_by_admin_id,updated_by_admin_id,row_version,created_at,updated_at)
  VALUES(p_country_id,\'closed\',p_unavailable_message_mode,p_market_content_id,IF(p_allow_global_wa_fallback=1,1,0),\'OFF\',v_admin_id,v_admin_id,1,NOW(6),NOW(6));
  SET p_row_version_after=1;
ELSE
  IF v_rv<>p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  UPDATE ctrl_market_entry_policy SET unavailable_message_mode=p_unavailable_message_mode, market_content_id=p_market_content_id, allow_global_wa_fallback=IF(p_allow_global_wa_fallback=1,1,0), international_shipping_mode=\'OFF\', updated_by_admin_id=v_admin_id, row_version=v_rv+1, updated_at=NOW(6) WHERE country_id=p_country_id AND row_version=v_rv;
  SET p_row_version_after=v_rv+1;
END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_entry_policy_upserted\',\'ctrl_market_entry_policy\',p_country_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_entry_policy_upsert\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_entry_policy_upserted\',\'ctrl_market_entry_policy\',p_country_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_entry_policy_upsert\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_market_entry_policy_status_transition`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_market_entry_policy_status_transition`(
  IN p_session_token_hash CHAR(64), IN p_country_id INT UNSIGNED, IN p_to_status VARCHAR(16), IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_from VARCHAR(16);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.market.policy.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT row_version, market_status INTO v_rv, v_from FROM ctrl_market_entry_policy WHERE country_id=p_country_id FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
IF NOT ((v_from=\'closed\' AND p_to_status IN (\'open\',\'suspended\',\'retired\')) OR (v_from=\'open\' AND p_to_status IN (\'suspended\',\'closed\',\'retired\')) OR (v_from=\'suspended\' AND p_to_status IN (\'open\',\'closed\',\'retired\')) OR (v_from=\'retired\' AND p_to_status=\'closed\')) THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'MARKET_E_TRANSITION\'; END IF;
UPDATE ctrl_market_entry_policy SET market_status=p_to_status, row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE country_id=p_country_id AND row_version=v_rv;
SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_entry_policy_status_changed\',\'ctrl_market_entry_policy\',p_country_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_entry_policy_status_transition\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_entry_policy_status_changed\',\'ctrl_market_entry_policy\',p_country_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_entry_policy_status_transition\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_market_content_upsert`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_market_content_upsert`(
  IN p_session_token_hash CHAR(64), IN p_content_uuid CHAR(36), IN p_content_key VARCHAR(64), IN p_mother_locale VARCHAR(8), IN p_mother_title_utf8 VARCHAR(191), IN p_mother_body_utf8 TEXT, IN p_expected_row_version INT, OUT p_content_id BIGINT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_id BIGINT UNSIGNED; DECLARE v_rv INT UNSIGNED; DECLARE v_hash CHAR(64); DECLARE v_rev INT UNSIGNED;
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.market.content.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SET v_hash = LOWER(SHA2(CONCAT(IFNULL(p_mother_locale,\'\'),\'|\',IFNULL(p_mother_title_utf8,\'\'),\'|\',IFNULL(p_mother_body_utf8,\'\')),256));
SELECT id,row_version,source_revision INTO v_id,v_rv,v_rev FROM ctrl_market_content WHERE content_uuid=p_content_uuid LIMIT 1 FOR UPDATE;
IF v_id IS NULL THEN
  IF p_expected_row_version<>0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CREATE_EXPECTED_ZERO\'; END IF;
  INSERT INTO ctrl_market_content(content_uuid,content_key,mother_locale,mother_title_utf8,mother_body_utf8,source_revision,source_hash,english_review_status,lifecycle_status,created_by_admin_id,updated_by_admin_id,row_version,created_at,updated_at)
  VALUES(p_content_uuid,p_content_key,p_mother_locale,p_mother_title_utf8,p_mother_body_utf8,1,v_hash,\'none\',\'draft\',v_admin_id,v_admin_id,1,NOW(6),NOW(6));
  SET p_content_id=LAST_INSERT_ID(); SET p_row_version_after=1;
ELSE
  IF v_rv<>p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  UPDATE ctrl_market_content SET content_key=p_content_key, mother_locale=p_mother_locale, mother_title_utf8=p_mother_title_utf8, mother_body_utf8=p_mother_body_utf8, source_revision=v_rev+1, source_hash=v_hash, updated_by_admin_id=v_admin_id, row_version=v_rv+1, updated_at=NOW(6) WHERE id=v_id AND row_version=v_rv;
  SET p_content_id=v_id; SET p_row_version_after=v_rv+1;
END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_upserted\',\'ctrl_market_content\',p_content_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_upsert\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_upserted\',\'ctrl_market_content\',p_content_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_upsert\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_market_content_lifecycle_transition`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_market_content_lifecycle_transition`(
  IN p_session_token_hash CHAR(64), IN p_content_id BIGINT, IN p_to_status VARCHAR(16), IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_from VARCHAR(16);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.market.content.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT row_version, lifecycle_status INTO v_rv, v_from FROM ctrl_market_content WHERE id=p_content_id FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
IF NOT ((v_from=\'draft\' AND p_to_status IN (\'approved\',\'retired\')) OR (v_from=\'approved\' AND p_to_status=\'retired\') OR (v_from=\'retired\' AND p_to_status=\'draft\')) THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CONTENT_E_TRANSITION\'; END IF;
UPDATE ctrl_market_content SET lifecycle_status=p_to_status, row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_content_id AND row_version=v_rv;
SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_lifecycle_changed\',\'ctrl_market_content\',p_content_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_lifecycle_transition\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_lifecycle_changed\',\'ctrl_market_content\',p_content_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_lifecycle_transition\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_market_content_english_review_set`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_market_content_english_review_set`(
  IN p_session_token_hash CHAR(64), IN p_content_id BIGINT, IN p_approved_en_title_utf8 VARCHAR(191), IN p_approved_en_body_utf8 TEXT, IN p_review_status VARCHAR(16), IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_rev INT UNSIGNED; DECLARE v_hash CHAR(64);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.market.content.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF p_review_status NOT IN (\'none\',\'draft\',\'approved\',\'rejected\',\'retired\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CONTENT_E_REVIEW\'; END IF;
SELECT row_version, IFNULL(approved_en_revision,0) INTO v_rv, v_rev FROM ctrl_market_content WHERE id=p_content_id FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
SET v_hash = LOWER(SHA2(CONCAT(IFNULL(p_approved_en_title_utf8,\'\'),\'|\',IFNULL(p_approved_en_body_utf8,\'\')),256));
UPDATE ctrl_market_content SET approved_en_title_utf8=p_approved_en_title_utf8, approved_en_body_utf8=p_approved_en_body_utf8, approved_en_revision=IF(p_review_status=\'approved\',v_rev+1,approved_en_revision), approved_en_hash=IF(p_review_status=\'approved\',v_hash,approved_en_hash), english_review_status=p_review_status, english_reviewed_at=NOW(6), english_reviewed_by_admin_id=v_admin_id, row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_content_id AND row_version=v_rv;
SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_english_reviewed\',\'ctrl_market_content\',p_content_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_english_review_set\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_english_reviewed\',\'ctrl_market_content\',p_content_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_english_review_set\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_market_content_i18n_upsert`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_market_content_i18n_upsert`(
  IN p_session_token_hash CHAR(64), IN p_content_id BIGINT, IN p_locale_code VARCHAR(8), IN p_title_utf8 VARCHAR(191), IN p_body_utf8 TEXT, IN p_expected_row_version INT, OUT p_i18n_id BIGINT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_id BIGINT UNSIGNED; DECLARE v_rv INT UNSIGNED; DECLARE v_en_rev INT UNSIGNED; DECLARE v_en_hash CHAR(64); DECLARE v_en_status VARCHAR(16);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.market.translation.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF LOWER(TRIM(p_locale_code))=\'en\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'I18N_E_EN_FORBIDDEN\'; END IF;
SELECT approved_en_revision, approved_en_hash, english_review_status INTO v_en_rev, v_en_hash, v_en_status FROM ctrl_market_content WHERE id=p_content_id FOR UPDATE;
IF v_en_status <> \'approved\' OR v_en_rev IS NULL OR v_en_hash IS NULL THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CONTENT_E_APPROVED_EN_REQUIRED\'; END IF;
SELECT id,row_version INTO v_id,v_rv FROM ctrl_market_content_i18n WHERE content_id=p_content_id AND locale_code=p_locale_code LIMIT 1 FOR UPDATE;
IF v_id IS NULL THEN
  IF p_expected_row_version<>0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CREATE_EXPECTED_ZERO\'; END IF;
  INSERT INTO ctrl_market_content_i18n(content_id,locale_code,title_utf8,body_utf8,translated_from_en_revision,translated_from_en_hash,translation_status,review_status,reviewed_by_admin_id,reviewed_at,translated_at,translated_by_admin_id,created_by_admin_id,updated_by_admin_id,row_version,created_at,updated_at)
  VALUES(p_content_id,p_locale_code,p_title_utf8,p_body_utf8,v_en_rev,v_en_hash,\'draft\',\'unreviewed\',NULL,NULL,NOW(6),v_admin_id,v_admin_id,v_admin_id,1,NOW(6),NOW(6));
  SET p_i18n_id=LAST_INSERT_ID(); SET p_row_version_after=1;
ELSE
  IF v_rv<>p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  UPDATE ctrl_market_content_i18n SET title_utf8=p_title_utf8, body_utf8=p_body_utf8, translated_from_en_revision=v_en_rev, translated_from_en_hash=v_en_hash, translation_status=\'draft\', review_status=\'unreviewed\', reviewed_by_admin_id=NULL, reviewed_at=NULL, translated_at=NOW(6), translated_by_admin_id=v_admin_id, updated_by_admin_id=v_admin_id, row_version=v_rv+1, updated_at=NOW(6) WHERE id=v_id AND row_version=v_rv;
  SET p_i18n_id=v_id; SET p_row_version_after=v_rv+1;
END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_i18n_upserted\',\'ctrl_market_content_i18n\',p_i18n_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_i18n_upsert\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_i18n_upserted\',\'ctrl_market_content_i18n\',p_i18n_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_i18n_upsert\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_market_content_i18n_status_transition`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_market_content_i18n_status_transition`(
  IN p_session_token_hash CHAR(64), IN p_i18n_id BIGINT, IN p_translation_status VARCHAR(16), IN p_review_status VARCHAR(16), IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_cid BIGINT UNSIGNED; DECLARE v_bind_rev INT UNSIGNED; DECLARE v_bind_hash CHAR(64); DECLARE v_cur_rev INT UNSIGNED; DECLARE v_cur_hash CHAR(64);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.market.translation.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF p_translation_status NOT IN (\'draft\',\'translated\',\'stale\',\'retired\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'I18N_E_TRANSLATION_STATUS\'; END IF;
IF p_review_status NOT IN (\'unreviewed\',\'approved\',\'rejected\',\'retired\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'I18N_E_REVIEW_STATUS\'; END IF;
SELECT row_version, content_id, translated_from_en_revision, translated_from_en_hash INTO v_rv, v_cid, v_bind_rev, v_bind_hash FROM ctrl_market_content_i18n WHERE id=p_i18n_id FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
SELECT approved_en_revision, approved_en_hash INTO v_cur_rev, v_cur_hash FROM ctrl_market_content WHERE id=v_cid FOR UPDATE;
IF p_review_status=\'approved\' THEN
  SELECT english_review_status INTO @en_rs FROM ctrl_market_content WHERE id=v_cid;
  IF @en_rs IS NULL OR @en_rs <> \'approved\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'I18N_E_ENGLISH_NOT_APPROVED\'; END IF;
  IF v_cur_rev IS NULL OR v_cur_hash IS NULL OR v_bind_rev <> v_cur_rev OR v_bind_hash <> v_cur_hash THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'I18N_E_STALE_LINEAGE\'; END IF;
  IF p_translation_status NOT IN (\'translated\') THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'I18N_E_NONPUBLISHABLE_STATUS\'; END IF;
END IF;
IF v_cur_rev IS NOT NULL AND (v_bind_rev <> v_cur_rev OR v_bind_hash <> v_cur_hash) AND p_translation_status <> \'retired\' THEN
  SET p_translation_status=\'stale\';
END IF;
UPDATE ctrl_market_content_i18n SET translation_status=p_translation_status, review_status=p_review_status, reviewed_at=IF(p_review_status=\'approved\',NOW(6),reviewed_at), reviewed_by_admin_id=IF(p_review_status=\'approved\',v_admin_id,reviewed_by_admin_id), updated_by_admin_id=v_admin_id, row_version=v_rv+1, updated_at=NOW(6) WHERE id=p_i18n_id AND row_version=v_rv;
SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_i18n_status_changed\',\'ctrl_market_content_i18n\',p_i18n_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_i18n_status_transition\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'market_content_i18n_status_changed\',\'ctrl_market_content_i18n\',p_i18n_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_market_content_i18n_status_transition\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_wa_global_upsert`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_wa_global_upsert`(
  IN p_session_token_hash CHAR(64), IN p_wa_uuid CHAR(36), IN p_display_label VARCHAR(191), IN p_prefilled_message_content_id BIGINT, IN p_normalized_e164 VARCHAR(32), IN p_expected_row_version INT, OUT p_id BIGINT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_id BIGINT UNSIGNED; DECLARE v_rv INT UNSIGNED;
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.whatsapp.global.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF p_normalized_e164 IS NULL OR p_normalized_e164 NOT REGEXP \'^\\\\+[1-9][0-9]{7,14}$\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'WA_E_E164\'; END IF;
SELECT id,row_version INTO v_id,v_rv FROM ctrl_contact_whatsapp_global WHERE wa_uuid=p_wa_uuid LIMIT 1 FOR UPDATE;
IF v_id IS NULL THEN
  IF p_expected_row_version<>0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CREATE_EXPECTED_ZERO\'; END IF;
  INSERT INTO ctrl_contact_whatsapp_global(wa_uuid,display_label,prefilled_message_content_id,normalized_e164,contact_status,created_by_admin_id,updated_by_admin_id,row_version,created_at,updated_at)
  VALUES(p_wa_uuid,p_display_label,p_prefilled_message_content_id,p_normalized_e164,\'draft\',v_admin_id,v_admin_id,1,NOW(6),NOW(6));
  SET p_id=LAST_INSERT_ID(); SET p_row_version_after=1;
ELSE
  IF v_rv<>p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  UPDATE ctrl_contact_whatsapp_global SET display_label=p_display_label, prefilled_message_content_id=p_prefilled_message_content_id, normalized_e164=p_normalized_e164, updated_by_admin_id=v_admin_id, row_version=v_rv+1, updated_at=NOW(6) WHERE id=v_id AND row_version=v_rv;
  SET p_id=v_id; SET p_row_version_after=v_rv+1;
END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'wa_global_upserted\',\'ctrl_contact_whatsapp_global\',p_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_wa_global_upsert\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'wa_global_upserted\',\'ctrl_contact_whatsapp_global\',p_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_wa_global_upsert\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_wa_global_status_transition`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_wa_global_status_transition`(
  IN p_session_token_hash CHAR(64), IN p_id BIGINT, IN p_to_status VARCHAR(16), IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_from VARCHAR(16);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.whatsapp.global.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT row_version, contact_status INTO v_rv, v_from FROM ctrl_contact_whatsapp_global WHERE id=p_id FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
IF NOT ((v_from=\'draft\' AND p_to_status IN (\'active\',\'disabled\',\'retired\')) OR (v_from=\'active\' AND p_to_status IN (\'disabled\',\'retired\',\'draft\')) OR (v_from=\'disabled\' AND p_to_status IN (\'active\',\'retired\',\'draft\')) OR (v_from=\'retired\' AND p_to_status=\'draft\')) THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'WA_E_TRANSITION\'; END IF;
UPDATE ctrl_contact_whatsapp_global SET contact_status=p_to_status, row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_id AND row_version=v_rv;
SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'wa_global_status_changed\',\'ctrl_contact_whatsapp_global\',p_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_wa_global_status_transition\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'wa_global_status_changed\',\'ctrl_contact_whatsapp_global\',p_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_wa_global_status_transition\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_wa_country_upsert`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_wa_country_upsert`(
  IN p_session_token_hash CHAR(64), IN p_wa_uuid CHAR(36), IN p_country_id INT UNSIGNED, IN p_display_label VARCHAR(191), IN p_prefilled_message_content_id BIGINT, IN p_normalized_e164 VARCHAR(32), IN p_expected_row_version INT, OUT p_id BIGINT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_id BIGINT UNSIGNED; DECLARE v_rv INT UNSIGNED;
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.whatsapp.country.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
IF p_normalized_e164 IS NULL OR p_normalized_e164 NOT REGEXP \'^\\\\+[1-9][0-9]{7,14}$\' THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'WA_E_E164\'; END IF;
SELECT id,row_version INTO v_id,v_rv FROM ctrl_contact_whatsapp_country WHERE wa_uuid=p_wa_uuid LIMIT 1 FOR UPDATE;
IF v_id IS NULL THEN
  IF p_expected_row_version<>0 THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CREATE_EXPECTED_ZERO\'; END IF;
  INSERT INTO ctrl_contact_whatsapp_country(wa_uuid,country_id,display_label,prefilled_message_content_id,normalized_e164,contact_status,created_by_admin_id,updated_by_admin_id,row_version,created_at,updated_at)
  VALUES(p_wa_uuid,p_country_id,p_display_label,p_prefilled_message_content_id,p_normalized_e164,\'draft\',v_admin_id,v_admin_id,1,NOW(6),NOW(6));
  SET p_id=LAST_INSERT_ID(); SET p_row_version_after=1;
ELSE
  IF v_rv<>p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
  UPDATE ctrl_contact_whatsapp_country SET country_id=p_country_id, display_label=p_display_label, prefilled_message_content_id=p_prefilled_message_content_id, normalized_e164=p_normalized_e164, updated_by_admin_id=v_admin_id, row_version=v_rv+1, updated_at=NOW(6) WHERE id=v_id AND row_version=v_rv;
  SET p_id=v_id; SET p_row_version_after=v_rv+1;
END IF;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'wa_country_upserted\',\'ctrl_contact_whatsapp_country\',p_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_wa_country_upsert\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'wa_country_upserted\',\'ctrl_contact_whatsapp_country\',p_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_wa_country_upsert\'),NOW(6));

END');
    $pdo->exec('DROP PROCEDURE IF EXISTS `orange_ctrl_wa_country_status_transition`');
    $pdo->exec('CREATE DEFINER=CURRENT_USER PROCEDURE `orange_ctrl_wa_country_status_transition`(
  IN p_session_token_hash CHAR(64), IN p_id BIGINT, IN p_to_status VARCHAR(16), IN p_expected_row_version INT, OUT p_row_version_after INT
)
SQL SECURITY DEFINER
BEGIN
DECLARE v_admin_id INT UNSIGNED DEFAULT NULL;
DECLARE v_is_su TINYINT DEFAULT 0;
DECLARE v_perm TINYINT DEFAULT 0;
DECLARE v_rv INT UNSIGNED; DECLARE v_from VARCHAR(16);
DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN RESIGNAL; END;
SELECT s.admin_id, a.is_superuser INTO v_admin_id, v_is_su
  FROM ctrl_admin_sessions s
  INNER JOIN ctrl_admins a ON a.id = s.admin_id
 WHERE s.session_token_hash = LOWER(TRIM(p_session_token_hash))
   AND s.revoked_at IS NULL
   AND s.expires_at > NOW(6)
   AND (s.last_seen_at IS NULL OR TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW(6)) <= s.idle_timeout_sec)
   AND a.is_active = 1
   AND (a.password_changed_at IS NULL OR a.password_changed_at <= s.created_at)
 LIMIT 1 FOR UPDATE;
IF v_admin_id IS NULL THEN
  SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'SESSION_E_UNAUTHORIZED\';
END IF;
IF v_is_su <> 1 THEN
  SELECT IFNULL(can_edit,0) INTO v_perm
    FROM ctrl_admin_permissions
   WHERE admin_id = v_admin_id
     AND resource_key = \'control.whatsapp.country.write\'
   LIMIT 1;
  IF IFNULL(v_perm,0) <> 1 THEN
    SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'PERMISSION_E_DENIED\';
  END IF;
END IF;
SELECT row_version, contact_status INTO v_rv, v_from FROM ctrl_contact_whatsapp_country WHERE id=p_id FOR UPDATE;
IF v_rv IS NULL OR v_rv <> p_expected_row_version THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'CAS_E_CONFLICT\'; END IF;
IF NOT ((v_from=\'draft\' AND p_to_status IN (\'active\',\'disabled\',\'retired\')) OR (v_from=\'active\' AND p_to_status IN (\'disabled\',\'retired\',\'draft\')) OR (v_from=\'disabled\' AND p_to_status IN (\'active\',\'retired\',\'draft\')) OR (v_from=\'retired\' AND p_to_status=\'draft\')) THEN SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT=\'WA_E_TRANSITION\'; END IF;
UPDATE ctrl_contact_whatsapp_country SET contact_status=p_to_status, row_version=v_rv+1, updated_by_admin_id=v_admin_id, updated_at=NOW(6) WHERE id=p_id AND row_version=v_rv;
SET p_row_version_after=v_rv+1;
INSERT INTO ctrl_audit_events(event_uuid,event_type,entity_table,entity_id,actor_kind,actor_admin_id,correlation_id,payload_json,created_at) VALUES(UUID(),\'wa_country_status_changed\',\'ctrl_contact_whatsapp_country\',p_id,\'admin\',v_admin_id,NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_wa_country_status_transition\'),NOW(6));
INSERT INTO ctrl_trust_events(event_uuid,event_type,entity_table,entity_id,actor_mysql_user,actor_kind,correlation_id,payload_json,created_at) VALUES(UUID(),\'wa_country_status_changed\',\'ctrl_contact_whatsapp_country\',p_id,CURRENT_USER(),\'definer\',NULL,JSON_OBJECT(\'routine\',\'orange_ctrl_wa_country_status_transition\'),NOW(6));

END');
}


/** Embedded Rev5 exact verify authority (future-durable; no package ambient JSON). */
if (!defined('ORANGE_CONTROL_REV5_EXPECTED_OBJECTS_SHA256')) {
    define('ORANGE_CONTROL_REV5_EXPECTED_OBJECTS_SHA256', 'd4d58561dbdbcc09c885d71aa83d0274994a8b4ba65d98ee0c477857060ec72e');
}
if (!defined('ORANGE_CONTROL_REV5_EXPECTED_MANIFEST_C_SHA256')) {
    define('ORANGE_CONTROL_REV5_EXPECTED_MANIFEST_C_SHA256', 'bec8a59675b9f6a9cca9279a6528bc3d238adf27d41cf38c82c962642b48f528');
}
function orange_control_domain_verify(PDO $pdo): void
{
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($db === '') {
        throw new RuntimeException('schema_partial_or_incompatible:no_database');
    }
    $totalTables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=" . $pdo->quote($db) . " AND TABLE_TYPE='BASE TABLE'")->fetchColumn();
    if ($totalTables !== 28) {
        throw new RuntimeException('schema_partial_or_incompatible:table_count:' . $totalTables);
    }
    $expObj = defined('ORANGE_CONTROL_REV5_EXPECTED_OBJECTS_SHA256') ? (string)ORANGE_CONTROL_REV5_EXPECTED_OBJECTS_SHA256 : '';
    $expMc = defined('ORANGE_CONTROL_REV5_EXPECTED_MANIFEST_C_SHA256') ? (string)ORANGE_CONTROL_REV5_EXPECTED_MANIFEST_C_SHA256 : '';
    if ($expObj === '' || strlen($expObj) !== 64) {
        throw new RuntimeException('schema_partial_or_incompatible:expected_objects_hash_missing');
    }
    if ($expMc === '' || strlen($expMc) !== 64) {
        throw new RuntimeException('schema_partial_or_incompatible:expected_manifest_c_hash_missing');
    }
    $snap = orange_control_rev5_capture_physical_snapshot($pdo);
    $gotObj = hash('sha256', json_encode($snap['objects'], JSON_UNESCAPED_SLASHES));
    if (!hash_equals($expObj, $gotObj)) {
        throw new RuntimeException('schema_partial_or_incompatible:physical_authority_mismatch');
    }
    $gotMc = (string)($snap['manifest_c_hash'] ?? '');
    if ($gotMc === '' || !hash_equals($expMc, $gotMc)) {
        throw new RuntimeException('schema_partial_or_incompatible:manifest_c_mismatch');
    }
}

function orange_control_rev5_capture_physical_snapshot(PDO $pdo): array
{
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $objects = ['tables' => [], 'routines' => [], 'triggers' => [], 'foreign_keys' => []];
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=" . $pdo->quote($db) . " AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $cols = $pdo->prepare('SELECT ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
        $cols->execute([$db, $t]);
        $idx = $pdo->prepare('SELECT INDEX_NAME, NON_UNIQUE, INDEX_TYPE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $idx->execute([$db, $t]);
        $objects['tables'][$t] = [
            'columns' => $cols->fetchAll(PDO::FETCH_ASSOC),
            'indexes' => $idx->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
    $fks = $pdo->query("SELECT rc.CONSTRAINT_NAME, rc.TABLE_NAME, rc.REFERENCED_TABLE_NAME, rc.UPDATE_RULE, rc.DELETE_RULE,
        GROUP_CONCAT(kcu.COLUMN_NAME ORDER BY kcu.ORDINAL_POSITION SEPARATOR ',') AS local_cols,
        GROUP_CONCAT(kcu.REFERENCED_COLUMN_NAME ORDER BY kcu.ORDINAL_POSITION SEPARATOR ',') AS ref_cols
      FROM information_schema.REFERENTIAL_CONSTRAINTS rc
      INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
        ON kcu.CONSTRAINT_SCHEMA=rc.CONSTRAINT_SCHEMA AND kcu.CONSTRAINT_NAME=rc.CONSTRAINT_NAME AND kcu.TABLE_NAME=rc.TABLE_NAME
      WHERE rc.CONSTRAINT_SCHEMA=" . $pdo->quote($db) . "
      GROUP BY rc.CONSTRAINT_NAME, rc.TABLE_NAME, rc.REFERENCED_TABLE_NAME, rc.UPDATE_RULE, rc.DELETE_RULE
      ORDER BY rc.CONSTRAINT_NAME")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fks as $fk) {
        $objects['foreign_keys'][$fk['CONSTRAINT_NAME']] = $fk;
    }
    $trigs = $pdo->query("SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=" . $pdo->quote($db) . " ORDER BY TRIGGER_NAME")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trigs as $tr) {
        $norm = preg_replace('/\s+/', ' ', trim((string)$tr['ACTION_STATEMENT']));
        $objects['triggers'][$tr['TRIGGER_NAME']] = [
            'table' => $tr['EVENT_OBJECT_TABLE'],
            'timing' => $tr['ACTION_TIMING'],
            'event' => $tr['EVENT_MANIPULATION'],
            'body_normalized' => $norm,
            'body_hash' => hash('sha256', (string)$norm),
        ];
    }
    $routines = $pdo->query("SELECT ROUTINE_NAME, ROUTINE_TYPE, SECURITY_TYPE, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=" . $pdo->quote($db) . " ORDER BY ROUTINE_NAME")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($routines as $r) {
        $pst = $pdo->prepare("SELECT PARAMETER_NAME, DTD_IDENTIFIER, PARAMETER_MODE, ORDINAL_POSITION FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=? AND SPECIFIC_NAME=? ORDER BY ORDINAL_POSITION");
        $pst->execute([$db, $r['ROUTINE_NAME']]);
        $params = $pst->fetchAll(PDO::FETCH_ASSOC);
        $norm = preg_replace('/\s+/', ' ', trim((string)$r['ROUTINE_DEFINITION']));
        $objects['routines'][$r['ROUTINE_NAME']] = [
            'type' => $r['ROUTINE_TYPE'],
            'security' => $r['SECURITY_TYPE'],
            'parameters' => $params,
            'body_normalized' => $norm,
            'body_hash' => hash('sha256', (string)$norm),
        ];
    }
    $manifestC = function_exists('orange_control_physical_manifest_hash')
        ? orange_control_physical_manifest_hash($pdo, $db, 5)
        : '';
    return [
        'schema' => $db,
        'table_count' => count($objects['tables']),
        'routine_count' => count($objects['routines']),
        'trigger_count' => count($objects['triggers']),
        'fk_count' => count($objects['foreign_keys']),
        'manifest_c_hash' => $manifestC,
        'objects' => $objects,
    ];
}

function orange_control_domain_install_fresh_or_migrate(PDO $pdo): void
{
    orange_control_domain_install_tables($pdo);
    orange_control_domain_install_routines($pdo);
    orange_control_domain_verify($pdo);
}