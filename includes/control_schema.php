<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1A Trust-Core + Phase 2.1B.1 Central Identity + Phase 2.1B.2 Locale (revision 4).
 *
 * Disposable local Control DB only. Does not touch Country Schema / orange_db /
 * current db() / Backup / Restore / Production includes / login wiring.
 *
 * Rev 2: Model C DEFINER Trust + Trust-Event B + UUID ledger + Manifest-C.
 * Rev 3: + dormant Central Identity package (control_identity_schema.php).
 * Rev 4: + dormant locale policy package (control_locale_schema.php).
 */

require_once __DIR__ . '/control_fingerprint.php';
require_once __DIR__ . '/control_domain_schema.php';

require_once __DIR__ . '/control_identity_schema.php';
require_once __DIR__ . '/control_locale_schema.php';

if (!defined('ORANGE_CONTROL_SCHEMA_REVISION')) {
    define('ORANGE_CONTROL_SCHEMA_REVISION', 5);
}

/** Phase 2.1A activation / replacement gates (fail closed; mirrored into ctrl_schema_meta). */
if (!defined('ORANGE_CONTROL_COUNTRY_ACTIVE_TRANSITION_ENABLED')) {
    define('ORANGE_CONTROL_COUNTRY_ACTIVE_TRANSITION_ENABLED', 0);
}
if (!defined('ORANGE_CONTROL_REGISTRY_ACTIVE_TRANSITION_ENABLED')) {
    define('ORANGE_CONTROL_REGISTRY_ACTIVE_TRANSITION_ENABLED', 0);
}
if (!defined('ORANGE_CONTROL_DATABASE_REPLACEMENT_TRANSITION_ENABLED')) {
    define('ORANGE_CONTROL_DATABASE_REPLACEMENT_TRANSITION_ENABLED', 0);
}

/** Trust-reserved event types — ctrl_trust_events only (never ctrl_audit_events). */
function orange_control_trust_event_types(): array
{
    return [
        'registry_trust_transition',
        'country_lifecycle_transition',
        'database_replacement',
        'schema_gate_denied',
    ];
}

/** Telemetry-only event types allowed for control_runtime / emit_telemetry. */
function orange_control_telemetry_event_types(): array
{
    return [
        'request',
        'job_request',
        'health_note',
        'runtime_probe',
    ];
}

/** @return list<string> */
function orange_control_trust_table_names(): array
{
    return [
        'ctrl_countries',
        'ctrl_db_host_profiles',
        'ctrl_reserved_database_names',
        'ctrl_country_db_registry',
        'ctrl_country_db_registry_history',
        'ctrl_secret_refs',
        'ctrl_runtime_principals',
        'ctrl_schema_meta',
        'ctrl_audit_events',
        'ctrl_trust_events',
        'ctrl_database_uuid_ledger',
    ];
}

/**
 * Strip MySQL charset introducers from GENERATION_EXPRESSION for stable hashing.
 */
function orange_control_normalize_generation_expression(?string $expr): string
{
    if ($expr === null || $expr === '') {
        return '';
    }
    $s = preg_replace("/_[a-zA-Z0-9]+(?=('))/", '', $expr);
    if (!is_string($s)) {
        $s = $expr;
    }

    return strtolower(preg_replace('/\s+/', ' ', trim($s)) ?? trim($s));
}

/**
 * Physical Manifest-C: information_schema fingerprint.
 * Authority for ctrl_schema_meta.schema_manifest_hash (not PHP table.column list).
 *
 * @param int|null $algorithmRevision 2 = Phase-2.1A name/type/security only;
 *                                    3+/null = include normalized routine bodies + DEFINER token.
 */
function orange_control_physical_manifest_canonical(PDO $pdo, string $schemaName, ?int $algorithmRevision = null): string
{
    $schema = strtolower(trim($schemaName));
    if ($schema === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $schema)) {
        throw new OrangeControlTrustException('bad_schema_name_for_manifest');
    }
    $algo = $algorithmRevision ?? (int) ORANGE_CONTROL_SCHEMA_REVISION;
    $sep = ORANGE_CONTROL_FP_SEP;
    $lines = [];

    $colStmt = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA,
                GENERATION_EXPRESSION, NUMERIC_PRECISION, NUMERIC_SCALE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $colStmt->execute([$schema]);
    while ($r = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $lines[] = implode($sep, [
            'COL',
            strtolower((string) $r['TABLE_NAME']),
            strtolower((string) $r['COLUMN_NAME']),
            strtolower((string) $r['COLUMN_TYPE']),
            strtoupper((string) $r['IS_NULLABLE']),
            strtoupper((string) ($r['COLUMN_KEY'] ?? '')),
            strtolower((string) ($r['EXTRA'] ?? '')),
            orange_control_normalize_generation_expression(
                isset($r['GENERATION_EXPRESSION']) ? (string) $r['GENERATION_EXPRESSION'] : null
            ),
            $r['NUMERIC_PRECISION'] === null ? '' : (string) (int) $r['NUMERIC_PRECISION'],
            $r['NUMERIC_SCALE'] === null ? '' : (string) (int) $r['NUMERIC_SCALE'],
        ]);
    }

    $idxStmt = $pdo->prepare(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
    );
    $idxStmt->execute([$schema]);
    while ($r = $idxStmt->fetch(PDO::FETCH_ASSOC)) {
        $lines[] = implode($sep, [
            'IDX',
            strtolower((string) $r['TABLE_NAME']),
            strtolower((string) $r['INDEX_NAME']),
            (string) (int) $r['NON_UNIQUE'],
            (string) (int) $r['SEQ_IN_INDEX'],
            strtolower((string) $r['COLUMN_NAME']),
            strtoupper((string) ($r['INDEX_TYPE'] ?? '')),
        ]);
    }

    $tcStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME, TABLE_NAME, CONSTRAINT_TYPE
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = ?
         ORDER BY TABLE_NAME, CONSTRAINT_NAME"
    );
    $tcStmt->execute([$schema]);
    while ($r = $tcStmt->fetch(PDO::FETCH_ASSOC)) {
        $lines[] = implode($sep, [
            'TC',
            strtolower((string) $r['TABLE_NAME']),
            strtolower((string) $r['CONSTRAINT_NAME']),
            strtoupper((string) $r['CONSTRAINT_TYPE']),
        ]);
    }

    if ($algo >= 3) {
        $rtStmt = $pdo->prepare(
            "SELECT ROUTINE_NAME, ROUTINE_TYPE, SECURITY_TYPE, DEFINER, ROUTINE_DEFINITION
             FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = ?
             ORDER BY ROUTINE_TYPE, ROUTINE_NAME"
        );
        $rtStmt->execute([$schema]);
        while ($r = $rtStmt->fetch(PDO::FETCH_ASSOC)) {
            $body = (string) ($r['ROUTINE_DEFINITION'] ?? '');
            $bodyNorm = strtolower(preg_replace('/\s+/', ' ', trim($body)) ?? trim($body));
            $lines[] = implode($sep, [
                'RTN',
                strtoupper((string) $r['ROUTINE_TYPE']),
                strtolower((string) $r['ROUTINE_NAME']),
                strtoupper((string) ($r['SECURITY_TYPE'] ?? '')),
                'DEFINER=@NORMALIZED@',
                hash('sha256', $bodyNorm, false),
            ]);
        }

        $paramStmt = $pdo->prepare(
            "SELECT SPECIFIC_NAME, ORDINAL_POSITION, PARAMETER_MODE, PARAMETER_NAME, DTD_IDENTIFIER
             FROM information_schema.PARAMETERS
             WHERE SPECIFIC_SCHEMA = ? AND ROUTINE_TYPE = 'PROCEDURE'
             ORDER BY SPECIFIC_NAME, ORDINAL_POSITION"
        );
        $paramStmt->execute([$schema]);
        while ($r = $paramStmt->fetch(PDO::FETCH_ASSOC)) {
            $lines[] = implode($sep, [
                'PRM',
                strtolower((string) $r['SPECIFIC_NAME']),
                (string) (int) $r['ORDINAL_POSITION'],
                strtoupper((string) ($r['PARAMETER_MODE'] ?? '')),
                strtolower((string) ($r['PARAMETER_NAME'] ?? '')),
                strtolower((string) ($r['DTD_IDENTIFIER'] ?? '')),
            ]);
        }
    } else {
        $rtStmt = $pdo->prepare(
            "SELECT ROUTINE_NAME, ROUTINE_TYPE, SECURITY_TYPE
             FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = ?
             ORDER BY ROUTINE_TYPE, ROUTINE_NAME"
        );
        $rtStmt->execute([$schema]);
        while ($r = $rtStmt->fetch(PDO::FETCH_ASSOC)) {
            $lines[] = implode($sep, [
                'RTN',
                strtoupper((string) $r['ROUTINE_TYPE']),
                strtolower((string) $r['ROUTINE_NAME']),
                strtoupper((string) ($r['SECURITY_TYPE'] ?? '')),
            ]);
        }
    }

    $trStmt = $pdo->prepare(
        "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = ?
         ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME"
    );
    $trStmt->execute([$schema]);
    while ($r = $trStmt->fetch(PDO::FETCH_ASSOC)) {
        $lines[] = implode($sep, [
            'TRG',
            strtolower((string) $r['EVENT_OBJECT_TABLE']),
            strtolower((string) $r['TRIGGER_NAME']),
            strtoupper((string) $r['ACTION_TIMING']),
            strtoupper((string) $r['EVENT_MANIPULATION']),
        ]);
    }

    $lines[] = 'revision:' . (string) $algo;
    sort($lines, SORT_STRING);

    return implode("\n", $lines);
}

function orange_control_physical_manifest_hash(PDO $pdo, string $schemaName, ?int $algorithmRevision = null): string
{
    return hash('sha256', orange_control_physical_manifest_canonical($pdo, $schemaName, $algorithmRevision), false);
}

/**
 * Map MySQL SIGNAL / privilege errors into OrangeControlTrustException when possible.
 */
function orange_control_throw_from_pdo(PDOException $e): void
{
    $sqlState = (string) ($e->errorInfo[0] ?? '');
    $driverCode = (int) ($e->errorInfo[1] ?? 0);
    $msg = (string) $e->getMessage();
    $known = [
        'phase21c_prerequisites_not_available',
        'database_uuid_direct_update_denied',
        'database_uuid_previously_registered',
        'row_version_conflict',
        'trust_mutation_forbidden_direct_sql',
        'trust_event_forbidden_actor',
        'schema_meta_revision_mismatch',
        'schema_manifest_physical_mismatch',
        'disallowed_registry_change_field',
        'registry_not_found',
        'invalid_country_lifecycle_status',
        'country_not_found',
        'host_profile_missing',
        'injected_failure_after_history',
        'injected_failure_after_registry',
        'telemetry_event_type_forbidden',
        'secret_version_invalid',
        'metadata_json_too_large',
        'audit_payload_too_large',
        'identity_actor_orphan_detected',
        'identity_authorization_denied',
        'identity_self_escalation_denied',
        'last_active_superuser_required',
        'identity_event_type_forbidden',
        'identity_username_invalid',
        'identity_outer_transaction_forbidden',
        'identity_issuer_session_required',
        'identity_session_country_pair_invalid',
        'identity_session_country_pair_mismatch',
        'schema_partial_or_incompatible',
        'locale_outer_transaction_forbidden',
        'locale_authorization_denied',
        'locale_session_proof_invalid',
        'locale_code_invalid',
        'locale_dir_invalid',
        'locale_numbering_system_forbidden',
        'locale_global_inactive',
        'locale_country_not_found',
        'locale_default_requires_enabled',
        'locale_default_slot_conflict',
        'locale_countries_cache_write_forbidden',
        'locale_unknown',
        'locale_admin_not_found',
        'locale_session_not_found',
    ];
    foreach ($known as $code) {
        if (str_contains($msg, $code)) {
            throw new OrangeControlTrustException($code, $msg);
        }
    }
    if ($driverCode === 1062 || $sqlState === '23000') {
        if (stripos($msg, 'database_uuid') !== false || stripos($msg, 'uq_') !== false
            || stripos($msg, 'ctrl_database_uuid_ledger') !== false
        ) {
            throw new OrangeControlTrustException('database_uuid_previously_registered', $msg);
        }
    }
    throw $e;
}

function orange_control_current_schema_name(PDO $pdo): string
{
    $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($db === '') {
        throw new OrangeControlTrustException('no_database_selected');
    }

    return $db;
}

/**
 * Install / verify Control Trust-Core DDL + routines + triggers.
 *
 * @param array{
 *   ownership_token?:string,
 *   ownership_run_id?:string,
 *   installed_by?:string,
 *   allow_meta_insert?:bool
 * } $opts
 */
function orange_control_ensure_schema(PDO $pdo, array $opts = []): void
{
    $pdo->exec('SET NAMES utf8mb4');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_countries (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            country_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            code VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name_ar VARCHAR(191) NOT NULL,
            name_en VARCHAR(191) NOT NULL,
            name_fil VARCHAR(191) NULL,
            name_hi VARCHAR(191) NULL,
            currency_code VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            timezone VARCHAR(64) NOT NULL,
            locale VARCHAR(16) NULL,
            default_language VARCHAR(8) NULL,
            phone_dial_code VARCHAR(8) NULL,
            decimal_policy_ref VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            sort_order INT NOT NULL DEFAULT 0,
            lifecycle_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            is_active TINYINT(1) GENERATED ALWAYS AS (lifecycle_status = 'active') STORED,
            activated_at DATETIME(6) NULL,
            suspended_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            created_by_admin_id INT NULL,
            updated_by_admin_id INT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_countries_uuid (country_uuid),
            UNIQUE KEY uq_ctrl_countries_code (code),
            UNIQUE KEY uq_ctrl_countries_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_db_host_profiles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            environment VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            display_name VARCHAR(191) NOT NULL,
            allowed_host_literal VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
            host_resolution_mode VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            env_host_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            env_port_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            default_port INT NOT NULL DEFAULT 3306,
            require_tls TINYINT(1) NOT NULL DEFAULT 0,
            verify_server_cert TINYINT(1) NOT NULL DEFAULT 0,
            connect_timeout_sec INT NOT NULL DEFAULT 5,
            profile_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_host_profile_key (profile_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_reserved_database_names (
            db_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (db_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_secret_refs (
            secret_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            purpose VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            country_id INT UNSIGNED NULL,
            current_version INT UNSIGNED NOT NULL,
            previous_version INT UNSIGNED NULL,
            rotation_state VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            rotated_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (secret_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_runtime_principals (
            principal_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            mysql_user VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            host_pattern VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            country_id INT UNSIGNED NULL,
            role_kind VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            secret_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            principal_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            is_active TINYINT(1) GENERATED ALWAYS AS (principal_status = 'active') STORED,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (principal_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_country_db_registry (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            database_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            country_id INT UNSIGNED NOT NULL,
            country_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            db_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            db_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            host_profile_id INT UNSIGNED NOT NULL,
            port_override INT NULL,
            secret_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            secret_version INT UNSIGNED NOT NULL,
            runtime_principal_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            required_schema_revision INT NOT NULL,
            installed_schema_revision INT NULL,
            schema_template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            identity_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            registry_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            is_routable TINYINT(1) GENERATED ALWAYS AS (registry_status = 'active') STORED,
            active_host_db_slot VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin
                GENERATED ALWAYS AS (
                    IF(registry_status IN ('allocating','installing','verifying','active','suspended'),
                       CONCAT(CAST(host_profile_id AS CHAR), '#', db_name),
                       NULL)
                ) STORED,
            secret_ref_slot VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin
                GENERATED ALWAYS AS (IF(registry_status <> 'retired', secret_ref, NULL)) STORED,
            principal_ref_slot VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin
                GENERATED ALWAYS AS (IF(registry_status <> 'retired', runtime_principal_ref, NULL)) STORED,
            health_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            failure_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            last_health_at DATETIME(6) NULL,
            last_verified_at DATETIME(6) NULL,
            activated_at DATETIME(6) NULL,
            row_version INT NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            created_by_admin_id INT NULL,
            updated_by_admin_id INT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_registry_country (country_id),
            UNIQUE KEY uq_registry_db_key (db_key),
            UNIQUE KEY uq_registry_database_uuid (database_uuid),
            UNIQUE KEY uq_active_host_db (active_host_db_slot),
            UNIQUE KEY uq_secret_ref_active (secret_ref_slot),
            UNIQUE KEY uq_principal_ref_active (principal_ref_slot),
            CONSTRAINT fk_registry_country FOREIGN KEY (country_id) REFERENCES ctrl_countries(id)
                ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT fk_registry_host FOREIGN KEY (host_profile_id) REFERENCES ctrl_db_host_profiles(id)
                ON UPDATE RESTRICT ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_country_db_registry_history (
            history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registry_id BIGINT UNSIGNED NOT NULL,
            country_id INT UNSIGNED NOT NULL,
            country_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            database_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            db_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            db_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            host_profile_id INT UNSIGNED NOT NULL,
            host_profile_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            secret_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            secret_version INT UNSIGNED NOT NULL,
            runtime_principal_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            required_schema_revision INT NOT NULL,
            installed_schema_revision INT NULL,
            schema_template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            identity_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            old_registry_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
            new_registry_status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            row_version_before INT NOT NULL,
            row_version_after INT NOT NULL,
            change_reason VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            changed_at DATETIME(6) NOT NULL,
            changed_by_admin_id INT NULL,
            snapshot_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            metadata_json JSON NULL,
            PRIMARY KEY (history_id),
            KEY idx_reghist_registry (registry_id, changed_at),
            KEY idx_reghist_country (country_id, changed_at),
            KEY idx_reghist_dbuuid (database_uuid),
            CONSTRAINT fk_reghist_registry FOREIGN KEY (registry_id) REFERENCES ctrl_country_db_registry(id)
                ON UPDATE RESTRICT ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_schema_meta (
            id TINYINT UNSIGNED NOT NULL,
            control_schema_revision INT UNSIGNED NOT NULL,
            schema_manifest_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            installed_at DATETIME(6) NOT NULL,
            installed_by VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            ownership_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            ownership_run_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            country_active_transition_enabled TINYINT(1) NOT NULL DEFAULT 0,
            registry_active_transition_enabled TINYINT(1) NOT NULL DEFAULT 0,
            database_replacement_transition_enabled TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_audit_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            entity_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            entity_id BIGINT UNSIGNED NULL,
            actor_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            actor_admin_id INT NULL,
            correlation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            payload_json JSON NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_audit_event_uuid (event_uuid),
            KEY idx_ctrl_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_trust_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            entity_table VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            entity_id BIGINT UNSIGNED NULL,
            actor_mysql_user VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            actor_kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            correlation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            payload_json JSON NULL,
            created_at DATETIME(6) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ctrl_trust_event_uuid (event_uuid),
            KEY idx_ctrl_trust_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ctrl_database_uuid_ledger (
            database_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            first_registry_id BIGINT UNSIGNED NOT NULL,
            first_country_id INT UNSIGNED NOT NULL,
            first_seen_at DATETIME(6) NOT NULL,
            source_history_id BIGINT UNSIGNED NULL,
            sealed_by_principal VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            PRIMARY KEY (database_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    orange_control_install_triggers($pdo);
    orange_control_install_routines($pdo);

    $schema = orange_control_current_schema_name($pdo);
    $rev = (int) ORANGE_CONTROL_SCHEMA_REVISION;
    $gateC = (int) ORANGE_CONTROL_COUNTRY_ACTIVE_TRANSITION_ENABLED;
    $gateR = (int) ORANGE_CONTROL_REGISTRY_ACTIVE_TRANSITION_ENABLED;
    $gateD = (int) ORANGE_CONTROL_DATABASE_REPLACEMENT_TRANSITION_ENABLED;

    $existing = $pdo->query(
        'SELECT control_schema_revision, schema_manifest_hash,
                country_active_transition_enabled, registry_active_transition_enabled,
                database_replacement_transition_enabled, ownership_token, ownership_run_id
         FROM ctrl_schema_meta WHERE id = 1'
    )->fetch(PDO::FETCH_ASSOC);

    // Legacy path retained: exact rev2 → identity package only when code target were 3.
    // At revision 4, unsupported jumps (2→4) fail closed via revision mismatch below.
    if (is_array($existing) && (int) $existing['control_schema_revision'] === 2 && $rev === 3) {
        $priorHash = orange_control_physical_manifest_hash($pdo, $schema, 2);
        if (!hash_equals((string) $existing['schema_manifest_hash'], $priorHash)) {
            throw new OrangeControlTrustException('schema_manifest_physical_mismatch');
        }
        if ((string) $existing['ownership_token'] === ''
            || !preg_match('/^[0-9a-f]{64}$/', (string) $existing['ownership_token'])
        ) {
            throw new OrangeControlTrustException('ownership_token_required');
        }
        if ((int) $existing['country_active_transition_enabled'] !== $gateC
            || (int) $existing['registry_active_transition_enabled'] !== $gateR
            || (int) $existing['database_replacement_transition_enabled'] !== $gateD
        ) {
            throw new OrangeControlTrustException('schema_meta_revision_mismatch');
        }

        orange_control_identity_migrate_2to3($pdo);
        $physHash = orange_control_physical_manifest_hash($pdo, $schema, 3);
        $by = isset($opts['installed_by']) ? strtolower(trim((string) $opts['installed_by'])) : 'phase21b1_migrate';
        $upd = $pdo->prepare(
            'UPDATE ctrl_schema_meta SET control_schema_revision = ?, schema_manifest_hash = ?,
                installed_at = NOW(6), installed_by = ?
             WHERE id = 1 AND control_schema_revision = 2'
        );
        $upd->execute([3, $physHash, $by]);
        if ($upd->rowCount() !== 1) {
            throw new OrangeControlTrustException('schema_meta_revision_mismatch');
        }

        return;
    }

    if (is_array($existing) && (int) $existing['control_schema_revision'] === 3 && $rev === 4) {
        // Validate exact revision-3 physical Manifest-C BEFORE locale DDL.
        orange_control_identity_verify_rev3($pdo);
        $priorHash = orange_control_physical_manifest_hash($pdo, $schema, 3);
        if (!hash_equals((string) $existing['schema_manifest_hash'], $priorHash)) {
            throw new OrangeControlTrustException('schema_manifest_physical_mismatch');
        }
        if ((string) $existing['ownership_token'] === ''
            || !preg_match('/^[0-9a-f]{64}$/', (string) $existing['ownership_token'])
        ) {
            throw new OrangeControlTrustException('ownership_token_required');
        }
        if ((int) $existing['country_active_transition_enabled'] !== $gateC
            || (int) $existing['registry_active_transition_enabled'] !== $gateR
            || (int) $existing['database_replacement_transition_enabled'] !== $gateD
        ) {
            throw new OrangeControlTrustException('schema_meta_revision_mismatch');
        }

        orange_control_locale_migrate_3to4($pdo);
        $physHash = orange_control_physical_manifest_hash($pdo, $schema, 4);
        $by = isset($opts['installed_by']) ? strtolower(trim((string) $opts['installed_by'])) : 'phase21b2_migrate';
        $upd = $pdo->prepare(
            'UPDATE ctrl_schema_meta SET control_schema_revision = ?, schema_manifest_hash = ?,
                installed_at = NOW(6), installed_by = ?
             WHERE id = 1 AND control_schema_revision = 3'
        );
        $upd->execute([$rev, $physHash, $by]);
        if ($upd->rowCount() !== 1) {
            throw new OrangeControlTrustException('schema_meta_revision_mismatch');
        }

        return;
    }

    
    // Rev4 → Rev5 (Phase 2.1B.4 domain) — Z03: Manifest-C match BEFORE any Rev5 DDL; full verify before CAS meta 4→5
    if (is_array($existing) && (int) $existing['control_schema_revision'] === 4 && $rev === 5) {
        orange_control_identity_verify_rev3($pdo);
        orange_control_locale_verify_rev4($pdo);
        if ((string) $existing['ownership_token'] === ''
            || !preg_match('/^[0-9a-f]{64}$/', (string) $existing['ownership_token'])
        ) {
            throw new OrangeControlTrustException('ownership_token_required');
        }
        $rev4Hash = orange_control_physical_manifest_hash($pdo, $schema, 4);
        if (!hash_equals((string) $existing['schema_manifest_hash'], $rev4Hash)) {
            throw new OrangeControlTrustException('rev4_pre_migration_manifest_mismatch');
        }
        orange_control_rev5_apply_domain_and_verify($pdo);
        orange_control_rev5_verify_only($pdo);
        $physHash = orange_control_physical_manifest_hash($pdo, $schema, 5);
        $by = isset($opts['installed_by']) ? strtolower(trim((string) $opts['installed_by'])) : 'phase21b4_migrate';
        $upd = $pdo->prepare(
            'UPDATE ctrl_schema_meta SET control_schema_revision = ?, schema_manifest_hash = ?,
                installed_at = NOW(6), installed_by = ?
             WHERE id = 1 AND control_schema_revision = 4'
        );
        $upd->execute([5, $physHash, $by]);
        if ($upd->rowCount() !== 1) {
            throw new OrangeControlTrustException('schema_meta_revision_mismatch');
        }
        orange_control_rev5_verify_only($pdo);
        return;
    }
if (is_array($existing)) {
        $installedRev = (int) $existing['control_schema_revision'];
        if ($installedRev !== $rev) {
            throw new OrangeControlTrustException('schema_meta_revision_mismatch');
        }
        // Stable rev4: verify physical packages BEFORE Manifest-C hash (no heal DDL).
        orange_control_identity_verify_rev3($pdo);
        orange_control_locale_verify_rev4($pdo);
        if ((int)$installedRev === 5 && (int)ORANGE_CONTROL_SCHEMA_REVISION === 5) {
            orange_control_rev5_verify_only($pdo);
        }
        $physHash = orange_control_physical_manifest_hash($pdo, $schema, $installedRev);
        if (!hash_equals((string) $existing['schema_manifest_hash'], $physHash)) {
            throw new OrangeControlTrustException('schema_manifest_physical_mismatch');
        }
        if ((int) $existing['country_active_transition_enabled'] !== $gateC
            || (int) $existing['registry_active_transition_enabled'] !== $gateR
            || (int) $existing['database_replacement_transition_enabled'] !== $gateD
        ) {
            throw new OrangeControlTrustException('schema_meta_revision_mismatch');
        }
        // Fail-closed: never silent ON DUPLICATE rewrite of revision/hash.
        return;
    }

    $allowInsert = array_key_exists('allow_meta_insert', $opts) ? (bool) $opts['allow_meta_insert'] : true;
    if (!$allowInsert) {
        throw new OrangeControlTrustException('schema_meta_revision_mismatch');
    }
    $token = isset($opts['ownership_token']) ? strtolower(trim((string) $opts['ownership_token'])) : '';
    $runId = isset($opts['ownership_run_id']) ? strtolower(trim((string) $opts['ownership_run_id'])) : '';
    $by = isset($opts['installed_by']) ? strtolower(trim((string) $opts['installed_by'])) : 'phase21b2_bootstrap';
    if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
        throw new OrangeControlTrustException('ownership_token_required');
    }
    if ($runId === '' || !preg_match('/^[a-z0-9]{6,32}$/', $runId)) {
        throw new OrangeControlTrustException('ownership_run_id_required');
    }

    orange_control_identity_install_fresh($pdo);
    orange_control_locale_install_fresh($pdo);
        if ((int)ORANGE_CONTROL_SCHEMA_REVISION === 5) {
            orange_control_rev5_apply_domain_and_verify($pdo);
        }
    $physHash = orange_control_physical_manifest_hash($pdo, $schema, $rev);

    $ins = $pdo->prepare(
        'INSERT INTO ctrl_schema_meta (
            id, control_schema_revision, schema_manifest_hash, installed_at, installed_by,
            ownership_token, ownership_run_id,
            country_active_transition_enabled, registry_active_transition_enabled,
            database_replacement_transition_enabled
         ) VALUES (1, ?, ?, NOW(6), ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$rev, $physHash, $by, $token, $runId, $gateC, $gateR, $gateD]);
}

function orange_control_install_triggers(PDO $pdo): void
{
    $drops = [
        'DROP TRIGGER IF EXISTS trg_ctrl_registry_bu_phase21a',
        'DROP TRIGGER IF EXISTS trg_ctrl_registry_bi_phase21a',
        'DROP TRIGGER IF EXISTS trg_ctrl_countries_bu_phase21a',
        'DROP TRIGGER IF EXISTS trg_ctrl_countries_bi_phase21a',
        'DROP TRIGGER IF EXISTS trg_ctrl_audit_bi_no_trust',
    ];
    foreach ($drops as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec(
        "CREATE TRIGGER trg_ctrl_registry_bu_phase21a
        BEFORE UPDATE ON ctrl_country_db_registry
        FOR EACH ROW
        BEGIN
            IF NEW.registry_status = 'active' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'phase21c_prerequisites_not_available';
            END IF;
            IF NOT (NEW.database_uuid <=> OLD.database_uuid) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'database_uuid_direct_update_denied';
            END IF;
        END"
    );

    $pdo->exec(
        "CREATE TRIGGER trg_ctrl_registry_bi_phase21a
        BEFORE INSERT ON ctrl_country_db_registry
        FOR EACH ROW
        BEGIN
            IF NEW.registry_status = 'active' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'phase21c_prerequisites_not_available';
            END IF;
        END"
    );

    $pdo->exec(
        "CREATE TRIGGER trg_ctrl_countries_bu_phase21a
        BEFORE UPDATE ON ctrl_countries
        FOR EACH ROW
        BEGIN
            IF NEW.lifecycle_status = 'active' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'phase21c_prerequisites_not_available';
            END IF;
        END"
    );

    $pdo->exec(
        "CREATE TRIGGER trg_ctrl_countries_bi_phase21a
        BEFORE INSERT ON ctrl_countries
        FOR EACH ROW
        BEGIN
            IF NEW.lifecycle_status = 'active' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'phase21c_prerequisites_not_available';
            END IF;
        END"
    );

    $pdo->exec(
        "CREATE TRIGGER trg_ctrl_audit_bi_no_trust
        BEFORE INSERT ON ctrl_audit_events
        FOR EACH ROW
        BEGIN
            IF NEW.event_type IN (
                'registry_trust_transition',
                'country_lifecycle_transition',
                'database_replacement',
                'schema_gate_denied'
            ) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'trust_event_forbidden_actor';
            END IF;
        END"
    );
}

function orange_control_install_routines(PDO $pdo): void
{
    $pdo->exec('DROP PROCEDURE IF EXISTS orange_ctrl_register_mapping');
    $pdo->exec('DROP PROCEDURE IF EXISTS orange_ctrl_registry_trust_transition');
    $pdo->exec('DROP PROCEDURE IF EXISTS orange_ctrl_country_lifecycle_transition');
    $pdo->exec('DROP PROCEDURE IF EXISTS orange_ctrl_emit_telemetry');

    // SQL SECURITY DEFINER — DEFINER = CURRENT_USER at CREATE (schema_admin co-located).
    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_register_mapping(
            IN p_database_uuid CHAR(36),
            IN p_country_id INT UNSIGNED,
            IN p_country_uuid CHAR(36),
            IN p_db_key VARCHAR(64),
            IN p_db_name VARCHAR(64),
            IN p_host_profile_id INT UNSIGNED,
            IN p_secret_ref VARCHAR(191),
            IN p_secret_version INT UNSIGNED,
            IN p_runtime_principal_ref VARCHAR(191),
            IN p_required_schema_revision INT,
            IN p_installed_schema_revision INT,
            IN p_schema_template_hash CHAR(64),
            IN p_identity_fingerprint CHAR(64),
            IN p_registry_status VARCHAR(32),
            OUT p_registry_id BIGINT UNSIGNED
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_status VARCHAR(32);
            DECLARE v_actor VARCHAR(128);
            DECLARE v_eu CHAR(36);
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                ROLLBACK;
                RESIGNAL;
            END;

            SET v_status = LOWER(TRIM(p_registry_status));
            IF v_status = 'active' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'phase21c_prerequisites_not_available';
            END IF;
            SET v_actor = CURRENT_USER();

            START TRANSACTION;
            INSERT INTO ctrl_country_db_registry (
                database_uuid, country_id, country_uuid, db_key, db_name, host_profile_id,
                secret_ref, secret_version, runtime_principal_ref, required_schema_revision,
                installed_schema_revision, schema_template_hash, identity_fingerprint,
                registry_status, row_version, created_at, updated_at
            ) VALUES (
                LOWER(p_database_uuid), p_country_id, LOWER(p_country_uuid), LOWER(p_db_key), LOWER(p_db_name),
                p_host_profile_id, LOWER(p_secret_ref), p_secret_version, LOWER(p_runtime_principal_ref),
                p_required_schema_revision, p_installed_schema_revision, LOWER(p_schema_template_hash),
                LOWER(p_identity_fingerprint), v_status, 1, NOW(6), NOW(6)
            );
            SET p_registry_id = LAST_INSERT_ID();
            INSERT INTO ctrl_database_uuid_ledger (
                database_uuid, first_registry_id, first_country_id, first_seen_at,
                source_history_id, sealed_by_principal
            ) VALUES (
                LOWER(p_database_uuid), p_registry_id, p_country_id, NOW(6), NULL, v_actor
            );
            COMMIT;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_registry_trust_transition(
            IN p_registry_id BIGINT UNSIGNED,
            IN p_expected_row_version INT,
            IN p_new_status VARCHAR(32),
            IN p_change_reason VARCHAR(64),
            IN p_secret_version INT,
            IN p_host_profile_id INT UNSIGNED,
            IN p_db_name VARCHAR(64),
            IN p_secret_ref VARCHAR(191),
            IN p_runtime_principal_ref VARCHAR(191),
            IN p_required_schema_revision INT,
            IN p_installed_schema_revision INT,
            IN p_schema_template_hash CHAR(64),
            IN p_identity_fingerprint CHAR(64),
            IN p_port_override INT,
            IN p_change_mask INT,
            IN p_inject_failure VARCHAR(32),
            IN p_actor_admin_id INT,
            IN p_snapshot_hash CHAR(64),
            OUT p_history_id BIGINT UNSIGNED,
            OUT p_row_version_after INT
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_old_status VARCHAR(32);
            DECLARE v_rv INT;
            DECLARE v_country_id INT UNSIGNED;
            DECLARE v_country_uuid CHAR(36);
            DECLARE v_database_uuid CHAR(36);
            DECLARE v_db_key VARCHAR(64);
            DECLARE v_db_name VARCHAR(64);
            DECLARE v_host_id INT UNSIGNED;
            DECLARE v_host_key VARCHAR(64);
            DECLARE v_secret_ref VARCHAR(191);
            DECLARE v_secret_ver INT UNSIGNED;
            DECLARE v_prin VARCHAR(191);
            DECLARE v_req INT;
            DECLARE v_inst INT;
            DECLARE v_tmpl CHAR(64);
            DECLARE v_fp CHAR(64);
            DECLARE v_port INT;
            DECLARE v_new VARCHAR(32);
            DECLARE v_gate TINYINT;
            DECLARE v_actor VARCHAR(128);
            DECLARE v_eu CHAR(36);
            DECLARE v_payload JSON;
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                ROLLBACK;
                RESIGNAL;
            END;

            SET v_new = LOWER(TRIM(IFNULL(p_new_status, '')));
            SET v_actor = CURRENT_USER();

            START TRANSACTION;
            SELECT registry_status, row_version, country_id, country_uuid, database_uuid, db_key, db_name,
                   host_profile_id, secret_ref, secret_version, runtime_principal_ref,
                   required_schema_revision, installed_schema_revision, schema_template_hash,
                   identity_fingerprint, port_override
              INTO v_old_status, v_rv, v_country_id, v_country_uuid, v_database_uuid, v_db_key, v_db_name,
                   v_host_id, v_secret_ref, v_secret_ver, v_prin,
                   v_req, v_inst, v_tmpl, v_fp, v_port
              FROM ctrl_country_db_registry
             WHERE id = p_registry_id
             FOR UPDATE;

            IF v_rv IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'registry_not_found';
            END IF;
            IF v_rv <> p_expected_row_version THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'row_version_conflict';
            END IF;

            IF v_new = '' THEN
                SET v_new = v_old_status;
            END IF;

            SELECT registry_active_transition_enabled INTO v_gate FROM ctrl_schema_meta WHERE id = 1;
            IF v_new = 'active' AND IFNULL(v_gate, 0) <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'phase21c_prerequisites_not_available';
            END IF;

            SELECT profile_key INTO v_host_key FROM ctrl_db_host_profiles WHERE id = v_host_id LIMIT 1;
            IF v_host_key IS NULL OR v_host_key = '' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'host_profile_missing';
            END IF;

            SET p_row_version_after = v_rv + 1;

            INSERT INTO ctrl_country_db_registry_history (
                registry_id, country_id, country_uuid, database_uuid, db_key, db_name,
                host_profile_id, host_profile_key, secret_ref, secret_version, runtime_principal_ref,
                required_schema_revision, installed_schema_revision, schema_template_hash, identity_fingerprint,
                old_registry_status, new_registry_status, row_version_before, row_version_after,
                change_reason, changed_at, changed_by_admin_id, snapshot_hash, metadata_json
            ) VALUES (
                p_registry_id, v_country_id, v_country_uuid, v_database_uuid, v_db_key, v_db_name,
                v_host_id, v_host_key, v_secret_ref, v_secret_ver, v_prin,
                v_req, v_inst, v_tmpl, v_fp,
                v_old_status, v_new, v_rv, p_row_version_after,
                LOWER(TRIM(p_change_reason)), NOW(6), p_actor_admin_id, LOWER(p_snapshot_hash), NULL
            );
            SET p_history_id = LAST_INSERT_ID();

            IF p_inject_failure = 'history' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected_failure_after_history';
            END IF;

            UPDATE ctrl_country_db_registry
               SET row_version = p_row_version_after,
                   updated_at = NOW(6),
                   registry_status = IF((p_change_mask & 1) <> 0, v_new, registry_status),
                   secret_version = IF((p_change_mask & 2) <> 0, p_secret_version, secret_version),
                   host_profile_id = IF((p_change_mask & 4) <> 0, p_host_profile_id, host_profile_id),
                   db_name = IF((p_change_mask & 8) <> 0, LOWER(p_db_name), db_name),
                   secret_ref = IF((p_change_mask & 16) <> 0, LOWER(p_secret_ref), secret_ref),
                   runtime_principal_ref = IF((p_change_mask & 32) <> 0, LOWER(p_runtime_principal_ref), runtime_principal_ref),
                   required_schema_revision = IF((p_change_mask & 64) <> 0, p_required_schema_revision, required_schema_revision),
                   installed_schema_revision = IF((p_change_mask & 128) <> 0, p_installed_schema_revision, installed_schema_revision),
                   schema_template_hash = IF((p_change_mask & 256) <> 0, LOWER(p_schema_template_hash), schema_template_hash),
                   identity_fingerprint = IF((p_change_mask & 512) <> 0, LOWER(p_identity_fingerprint), identity_fingerprint),
                   port_override = IF((p_change_mask & 1024) <> 0, p_port_override, port_override)
             WHERE id = p_registry_id;

            IF p_inject_failure = 'registry' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected_failure_after_registry';
            END IF;

            SET v_eu = LOWER(CONCAT(
                SUBSTR(MD5(RAND()), 1, 8), '-',
                SUBSTR(MD5(RAND()), 1, 4), '-4',
                SUBSTR(MD5(RAND()), 1, 3), '-',
                SUBSTR('89ab', 1 + FLOOR(RAND() * 4), 1), SUBSTR(MD5(RAND()), 1, 3), '-',
                SUBSTR(MD5(RAND()), 1, 12)
            ));
            SET v_payload = JSON_OBJECT(
                'change_reason', LOWER(TRIM(p_change_reason)),
                'old_status', v_old_status,
                'new_status', v_new,
                'row_version_before', v_rv,
                'row_version_after', p_row_version_after,
                'history_id', p_history_id,
                'snapshot_hash', LOWER(p_snapshot_hash)
            );
            INSERT INTO ctrl_trust_events (
                event_uuid, event_type, entity_table, entity_id,
                actor_mysql_user, actor_kind, correlation_id, payload_json, created_at
            ) VALUES (
                v_eu, 'registry_trust_transition', 'ctrl_country_db_registry', p_registry_id,
                v_actor, 'trust_executor', NULL, v_payload, NOW(6)
            );
            COMMIT;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_country_lifecycle_transition(
            IN p_country_id INT UNSIGNED,
            IN p_new_status VARCHAR(32)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_new VARCHAR(32);
            DECLARE v_gate TINYINT;
            DECLARE v_actor VARCHAR(128);
            DECLARE v_eu CHAR(36);
            DECLARE EXIT HANDLER FOR SQLEXCEPTION
            BEGIN
                ROLLBACK;
                RESIGNAL;
            END;

            SET v_new = LOWER(TRIM(p_new_status));
            SET v_actor = CURRENT_USER();
            IF v_new NOT IN ('draft','provisioning','ready','active','suspended','retired') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid_country_lifecycle_status';
            END IF;
            SELECT country_active_transition_enabled INTO v_gate FROM ctrl_schema_meta WHERE id = 1;
            IF v_new = 'active' AND IFNULL(v_gate, 0) <> 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'phase21c_prerequisites_not_available';
            END IF;

            START TRANSACTION;
            UPDATE ctrl_countries
               SET lifecycle_status = v_new, updated_at = NOW(6)
             WHERE id = p_country_id;
            IF ROW_COUNT() < 1 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'country_not_found';
            END IF;
            SET v_eu = LOWER(CONCAT(
                SUBSTR(MD5(RAND()), 1, 8), '-',
                SUBSTR(MD5(RAND()), 1, 4), '-4',
                SUBSTR(MD5(RAND()), 1, 3), '-',
                SUBSTR('89ab', 1 + FLOOR(RAND() * 4), 1), SUBSTR(MD5(RAND()), 1, 3), '-',
                SUBSTR(MD5(RAND()), 1, 12)
            ));
            INSERT INTO ctrl_trust_events (
                event_uuid, event_type, entity_table, entity_id,
                actor_mysql_user, actor_kind, correlation_id, payload_json, created_at
            ) VALUES (
                v_eu, 'country_lifecycle_transition', 'ctrl_countries', p_country_id,
                v_actor, 'trust_executor', NULL,
                JSON_OBJECT('new_status', v_new), NOW(6)
            );
            COMMIT;
        END"
    );

    $pdo->exec(
        "CREATE PROCEDURE orange_ctrl_emit_telemetry(
            IN p_event_type VARCHAR(64),
            IN p_entity_table VARCHAR(64),
            IN p_entity_id BIGINT UNSIGNED,
            IN p_payload_json JSON,
            IN p_correlation_id VARCHAR(64)
        )
        SQL SECURITY DEFINER
        BEGIN
            DECLARE v_type VARCHAR(64);
            DECLARE v_actor VARCHAR(128);
            DECLARE v_kind VARCHAR(32);
            DECLARE v_eu CHAR(36);
            SET v_type = LOWER(TRIM(p_event_type));
            IF v_type NOT IN ('request','job_request','health_note','runtime_probe') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'telemetry_event_type_forbidden';
            END IF;
            SET v_actor = CURRENT_USER();
            SET v_kind = IF(v_actor LIKE 'p21a_rt_%', 'control_runtime', 'trust_executor');
            SET v_eu = LOWER(CONCAT(
                SUBSTR(MD5(RAND()), 1, 8), '-',
                SUBSTR(MD5(RAND()), 1, 4), '-4',
                SUBSTR(MD5(RAND()), 1, 3), '-',
                SUBSTR('89ab', 1 + FLOOR(RAND() * 4), 1), SUBSTR(MD5(RAND()), 1, 3), '-',
                SUBSTR(MD5(RAND()), 1, 12)
            ));
            INSERT INTO ctrl_audit_events (
                event_uuid, event_type, entity_table, entity_id, actor_kind, actor_admin_id,
                correlation_id, payload_json, created_at
            ) VALUES (
                v_eu, v_type, p_entity_table, p_entity_id, v_kind, NULL,
                p_correlation_id, p_payload_json, NOW(6)
            );
        END"
    );
}

/**
 * @param array<string, string> $names map db_name => reason
 */
function orange_control_seed_reserved_names(PDO $pdo, array $names): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO ctrl_reserved_database_names (db_name, reason, created_at)
         VALUES (?, ?, NOW(6))'
    );
    foreach ($names as $dbName => $reason) {
        $n = strtolower(trim($dbName));
        if ($n === '' || !preg_match('/^[a-z0-9_]{1,64}$/', $n)) {
            throw new OrangeControlTrustException('bad_reserved_db_name');
        }
        try {
            $stmt->execute([$n, strtolower(trim($reason))]);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }
}

function orange_control_assert_database_uuid_available(
    PDO $pdo,
    string $databaseUuid,
    ?int $excludeCountryId = null
): void {
    $uuid = orange_control_assert_uuid_lowercase($databaseUuid, 'bad_database_uuid');

    $stmtL = $pdo->prepare('SELECT first_country_id FROM ctrl_database_uuid_ledger WHERE database_uuid = ? LIMIT 1');
    $stmtL->execute([$uuid]);
    $led = $stmtL->fetch(PDO::FETCH_ASSOC);
    if (is_array($led)) {
        $cid = (int) $led['first_country_id'];
        if ($excludeCountryId === null || $cid !== $excludeCountryId) {
            throw new OrangeControlTrustException('database_uuid_previously_registered');
        }
    }

    $sql = 'SELECT country_id FROM ctrl_country_db_registry WHERE database_uuid = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uuid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $cid = (int) $row['country_id'];
        if ($excludeCountryId === null || $cid !== $excludeCountryId) {
            throw new OrangeControlTrustException('database_uuid_previously_registered');
        }
    }

    $sqlH = 'SELECT h.country_id FROM ctrl_country_db_registry_history h WHERE h.database_uuid = ?';
    $params = [$uuid];
    if ($excludeCountryId !== null) {
        $sqlH .= ' AND h.country_id <> ?';
        $params[] = $excludeCountryId;
    }
    $sqlH .= ' LIMIT 1';
    $stmtH = $pdo->prepare($sqlH);
    $stmtH->execute($params);
    if ($stmtH->fetch(PDO::FETCH_ASSOC)) {
        throw new OrangeControlTrustException('database_uuid_previously_registered');
    }
}

/**
 * Register country DB mapping via DEFINER routine (ledger sealed).
 *
 * @param array<string, mixed> $fields
 */
function orange_control_register_mapping(PDO $pdo, array $fields): int
{
    $status = strtolower(trim((string) ($fields['registry_status'] ?? 'allocating')));
    if ($status === 'active' && (int) ORANGE_CONTROL_REGISTRY_ACTIVE_TRANSITION_ENABLED !== 1) {
        throw new OrangeControlTrustException('phase21c_prerequisites_not_available');
    }
    $uuid = orange_control_assert_uuid_lowercase((string) ($fields['database_uuid'] ?? ''), 'bad_database_uuid');
    orange_control_assert_database_uuid_available($pdo, $uuid, isset($fields['country_id']) ? (int) $fields['country_id'] : null);

    $secret = orange_control_validate_secret_ref((string) ($fields['secret_ref'] ?? ''));
    $prin = orange_control_validate_runtime_principal_ref((string) ($fields['runtime_principal_ref'] ?? ''));
    $tmpl = orange_control_assert_hex64((string) ($fields['schema_template_hash'] ?? ''), 'bad_schema_template_hash');
    $fp = orange_control_assert_hex64((string) ($fields['identity_fingerprint'] ?? ''), 'bad_identity_fingerprint');

    try {
        $stmt = $pdo->prepare(
            'CALL orange_ctrl_register_mapping(?,?,?,?,?,?,?,?,?,?,?,?,?,?,@p_registry_id)'
        );
        $stmt->execute([
            $uuid,
            (int) $fields['country_id'],
            orange_control_assert_uuid_lowercase((string) $fields['country_uuid'], 'bad_country_uuid'),
            strtolower(trim((string) $fields['db_key'])),
            strtolower(trim((string) $fields['db_name'])),
            (int) $fields['host_profile_id'],
            $secret,
            (int) $fields['secret_version'],
            $prin,
            (int) $fields['required_schema_revision'],
            array_key_exists('installed_schema_revision', $fields) && $fields['installed_schema_revision'] !== null
                ? (int) $fields['installed_schema_revision']
                : null,
            $tmpl,
            $fp,
            $status,
        ]);
        while ($stmt->nextRowset()) {
            // drain procedure result sets
        }
        $id = (int) $pdo->query('SELECT @p_registry_id')->fetchColumn();
        if ($id < 1) {
            throw new OrangeControlTrustException('register_mapping_failed');
        }

        return $id;
    } catch (PDOException $e) {
        orange_control_throw_from_pdo($e);
        throw $e;
    }
}

function orange_control_country_lifecycle_transition(
    PDO $pdo,
    int $countryId,
    string $newStatus,
    string $actorKind = 'trust_executor'
): void {
    unset($actorKind); // actor derived inside DEFINER routine from CURRENT_USER()
    $new = strtolower(trim($newStatus));
    if ($new === 'active' && (int) ORANGE_CONTROL_COUNTRY_ACTIVE_TRANSITION_ENABLED !== 1) {
        throw new OrangeControlTrustException('phase21c_prerequisites_not_available');
    }
    try {
        $stmt = $pdo->prepare('CALL orange_ctrl_country_lifecycle_transition(?, ?)');
        $stmt->execute([$countryId, $new]);
        while ($stmt->nextRowset()) {
            // drain
        }
    } catch (PDOException $e) {
        orange_control_throw_from_pdo($e);
    }
}

/**
 * Contained health-only update (no history / no row_version). Direct column UPDATE by trust_executor.
 *
 * @param array{health_status?:string,failure_code?:?string,last_health_at?:?string,last_verified_at?:?string} $patch
 */
function orange_control_registry_health_update(PDO $pdo, int $registryId, array $patch): void
{
    $fields = [];
    $params = [];
    foreach (['health_status', 'failure_code', 'last_health_at', 'last_verified_at'] as $col) {
        if (array_key_exists($col, $patch)) {
            $fields[] = $col . ' = ?';
            $params[] = $patch[$col];
        }
    }
    if ($fields === []) {
        throw new OrangeControlTrustException('health_patch_empty');
    }
    $params[] = $registryId;
    $sql = 'UPDATE ctrl_country_db_registry SET ' . implode(', ', $fields) . ' WHERE id = ?';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } catch (PDOException $e) {
        orange_control_throw_from_pdo($e);
    }
}

/**
 * Atomic Trust-Core registry transition via DEFINER routine (History + Trust Event).
 *
 * @param array<string, mixed> $changes
 * @param array{inject_failure_after?:?string,actor_admin_id?:?int,transition_code?:string,metadata?:array}|array<string,mixed> $options
 * @return array{history_id:int,row_version:int,snapshot_hash:string}
 */
function orange_control_registry_trust_transition(
    PDO $pdo,
    int $registryId,
    int $expectedRowVersion,
    array $changes,
    string $changeReason,
    array $options = []
): array {
    $inject = isset($options['inject_failure_after']) ? (string) $options['inject_failure_after'] : '';
    $actorAdminId = array_key_exists('actor_admin_id', $options) ? $options['actor_admin_id'] : null;
    $transitionCode = isset($options['transition_code']) ? (string) $options['transition_code'] : '';

    if ($transitionCode === 'database_replacement' || array_key_exists('database_uuid', $changes)) {
        if ($transitionCode === 'database_replacement') {
            if ((int) ORANGE_CONTROL_DATABASE_REPLACEMENT_TRANSITION_ENABLED !== 1) {
                throw new OrangeControlTrustException('phase21c_prerequisites_not_available');
            }
        } else {
            throw new OrangeControlTrustException('database_uuid_direct_update_denied');
        }
    }

    $allowedChangeKeys = [
        'registry_status',
        'host_profile_id',
        'db_name',
        'secret_ref',
        'secret_version',
        'runtime_principal_ref',
        'required_schema_revision',
        'installed_schema_revision',
        'schema_template_hash',
        'identity_fingerprint',
        'port_override',
    ];
    foreach (array_keys($changes) as $k) {
        if (!in_array($k, $allowedChangeKeys, true)) {
            throw new OrangeControlTrustException('disallowed_registry_change_field');
        }
    }

    $stmt = $pdo->prepare('SELECT * FROM ctrl_country_db_registry WHERE id = ?');
    $stmt->execute([$registryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new OrangeControlTrustException('registry_not_found');
    }
    if ((int) $row['row_version'] !== $expectedRowVersion) {
        throw new OrangeControlTrustException('row_version_conflict');
    }

    $newStatus = array_key_exists('registry_status', $changes)
        ? strtolower(trim((string) $changes['registry_status']))
        : (string) $row['registry_status'];
    if ($newStatus === 'active' && (int) ORANGE_CONTROL_REGISTRY_ACTIVE_TRANSITION_ENABLED !== 1) {
        throw new OrangeControlTrustException('phase21c_prerequisites_not_available');
    }

    $hpStmt = $pdo->prepare('SELECT profile_key FROM ctrl_db_host_profiles WHERE id = ? LIMIT 1');
    $hpStmt->execute([(int) $row['host_profile_id']]);
    $hostKey = (string) $hpStmt->fetchColumn();
    if ($hostKey === '') {
        throw new OrangeControlTrustException('host_profile_missing');
    }

    $preSnapshot = [
        'snapshot_hash_version' => 1,
        'registry_id' => (int) $row['id'],
        'country_id' => (int) $row['country_id'],
        'country_uuid' => (string) $row['country_uuid'],
        'database_uuid' => (string) $row['database_uuid'],
        'db_key' => (string) $row['db_key'],
        'db_name' => (string) $row['db_name'],
        'host_profile_id' => (int) $row['host_profile_id'],
        'host_profile_key' => $hostKey,
        'secret_ref' => (string) $row['secret_ref'],
        'secret_version' => (int) $row['secret_version'],
        'runtime_principal_ref' => (string) $row['runtime_principal_ref'],
        'required_schema_revision' => (int) $row['required_schema_revision'],
        'installed_schema_revision' => $row['installed_schema_revision'] === null
            ? null
            : (int) $row['installed_schema_revision'],
        'schema_template_hash' => (string) $row['schema_template_hash'],
        'identity_fingerprint' => (string) $row['identity_fingerprint'],
        'registry_status' => (string) $row['registry_status'],
        'row_version' => (int) $row['row_version'],
    ];
    $snapshotHash = orange_control_snapshot_hash_v1($preSnapshot);

    $mask = 0;
    $pNewStatus = $newStatus;
    $pSecretVer = (int) $row['secret_version'];
    $pHostId = (int) $row['host_profile_id'];
    $pDbName = (string) $row['db_name'];
    $pSecret = (string) $row['secret_ref'];
    $pPrin = (string) $row['runtime_principal_ref'];
    $pReq = (int) $row['required_schema_revision'];
    $pInst = $row['installed_schema_revision'] === null ? null : (int) $row['installed_schema_revision'];
    $pTmpl = (string) $row['schema_template_hash'];
    $pFp = (string) $row['identity_fingerprint'];
    $pPort = $row['port_override'] === null ? null : (int) $row['port_override'];

    if (array_key_exists('registry_status', $changes)) {
        $mask |= 1;
    }
    if (array_key_exists('secret_version', $changes)) {
        $mask |= 2;
        $pSecretVer = (int) $changes['secret_version'];
        if ($pSecretVer < 1) {
            throw new OrangeControlTrustException('secret_version_invalid');
        }
    }
    if (array_key_exists('host_profile_id', $changes)) {
        $mask |= 4;
        $pHostId = (int) $changes['host_profile_id'];
    }
    if (array_key_exists('db_name', $changes)) {
        $mask |= 8;
        $pDbName = strtolower(trim((string) $changes['db_name']));
    }
    if (array_key_exists('secret_ref', $changes)) {
        $mask |= 16;
        $pSecret = orange_control_validate_secret_ref((string) $changes['secret_ref']);
    }
    if (array_key_exists('runtime_principal_ref', $changes)) {
        $mask |= 32;
        $pPrin = orange_control_validate_runtime_principal_ref((string) $changes['runtime_principal_ref']);
    }
    if (array_key_exists('required_schema_revision', $changes)) {
        $mask |= 64;
        $pReq = (int) $changes['required_schema_revision'];
    }
    if (array_key_exists('installed_schema_revision', $changes)) {
        $mask |= 128;
        $pInst = $changes['installed_schema_revision'] === null ? null : (int) $changes['installed_schema_revision'];
    }
    if (array_key_exists('schema_template_hash', $changes)) {
        $mask |= 256;
        $pTmpl = orange_control_assert_hex64((string) $changes['schema_template_hash'], 'bad_schema_template_hash');
    }
    if (array_key_exists('identity_fingerprint', $changes)) {
        $mask |= 512;
        $pFp = orange_control_assert_hex64((string) $changes['identity_fingerprint'], 'bad_identity_fingerprint');
    }
    if (array_key_exists('port_override', $changes)) {
        $mask |= 1024;
        $pPort = $changes['port_override'] === null ? null : (int) $changes['port_override'];
    }

    try {
        // 18 IN placeholders + 2 user-var OUTs
        $call = $pdo->prepare(
            'CALL orange_ctrl_registry_trust_transition(
                ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
                @p_history_id, @p_row_version_after
            )'
        );
        $call->execute([
            $registryId,
            $expectedRowVersion,
            $pNewStatus,
            strtolower(trim($changeReason)),
            $pSecretVer,
            $pHostId,
            $pDbName,
            $pSecret,
            $pPrin,
            $pReq,
            $pInst,
            $pTmpl,
            $pFp,
            $pPort,
            $mask,
            $inject,
            is_int($actorAdminId) ? $actorAdminId : null,
            $snapshotHash,
        ]);
        while ($call->nextRowset()) {
            // drain
        }
        $histId = (int) $pdo->query('SELECT @p_history_id')->fetchColumn();
        $rvAfter = (int) $pdo->query('SELECT @p_row_version_after')->fetchColumn();

        return [
            'history_id' => $histId,
            'row_version' => $rvAfter,
            'snapshot_hash' => $snapshotHash,
        ];
    } catch (PDOException $e) {
        orange_control_throw_from_pdo($e);
        throw $e;
    }
}

/**
 * Telemetry insert into ctrl_audit_events (non-trust types only).
 *
 * @param array<string, mixed> $payload
 */
function orange_control_insert_audit(
    PDO $pdo,
    string $eventType,
    ?string $entityTable,
    ?int $entityId,
    string $actorKind,
    array $payload = [],
    ?int $actorAdminId = null,
    ?string $correlationId = null
): int {
    unset($actorKind, $actorAdminId); // actor stamped by routine / CURRENT_USER path
    $type = strtolower(trim($eventType));
    if (in_array($type, orange_control_trust_event_types(), true)) {
        throw new OrangeControlTrustException('trust_event_forbidden_actor');
    }
    if (!in_array($type, orange_control_telemetry_event_types(), true)) {
        throw new OrangeControlTrustException('telemetry_event_type_forbidden');
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 8192) {
        throw new OrangeControlTrustException('audit_payload_too_large');
    }
    try {
        $stmt = $pdo->prepare('CALL orange_ctrl_emit_telemetry(?, ?, ?, CAST(? AS JSON), ?)');
        $stmt->execute([$type, $entityTable, $entityId, $json, $correlationId]);
        while ($stmt->nextRowset()) {
            // drain
        }
        $id = (int) $pdo->query(
            "SELECT id FROM ctrl_audit_events WHERE event_type = " . $pdo->quote($type)
            . " ORDER BY id DESC LIMIT 1"
        )->fetchColumn();

        return $id;
    } catch (PDOException $e) {
        orange_control_throw_from_pdo($e);
        throw $e;
    }
}


/**
 * Phase 2.1B.4 Rev5 domain integration hooks (external candidate).
 * Meta revision advances to 5 only AFTER domain verify succeeds.
 */
function orange_control_rev5_apply_domain_and_verify(PDO $pdo): void
{
    orange_control_domain_install_fresh_or_migrate($pdo);
}

function orange_control_rev5_verify_only(PDO $pdo): void
{
    orange_control_domain_verify($pdo);
}
