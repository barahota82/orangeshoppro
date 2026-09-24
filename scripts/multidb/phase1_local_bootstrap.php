<?php
declare(strict_types=1);

/**
 * Orange Phase-1 — local disposable Control rev4 + KW/EG Country DB bootstrap.
 * Loopback MySQL only. Does not touch Production files or orange_db schema.
 *
 * Enables disposable active transitions via PHP constants BEFORE requiring
 * control_schema.php (DATA gate only — NOT a Control schema revision bump).
 *
 * Env:
 *   ORANGE_PHASE1_MYSQL_ADMIN_USER (default: root)
 *   ORANGE_PHASE1_MYSQL_ADMIN_PASS (default: empty)
 *   ORANGE_PHASE1_MYSQL_HOST (default: 127.0.0.1)
 *   ORANGE_PHASE1_MYSQL_PORT (default: 3306)
 *
 * Usage:
 *   php scripts/multidb/phase1_local_bootstrap.php
 *   php scripts/multidb/phase1_local_bootstrap.php --run-id=abc123
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$schemaRevision = 124;
$host = getenv('ORANGE_PHASE1_MYSQL_HOST');
$host = ($host !== false && trim((string) $host) !== '') ? trim((string) $host) : '127.0.0.1';
$port = getenv('ORANGE_PHASE1_MYSQL_PORT');
$port = ($port !== false && trim((string) $port) !== '') ? (int) $port : 3306;
$adminUser = getenv('ORANGE_PHASE1_MYSQL_ADMIN_USER');
$adminUser = ($adminUser !== false && trim((string) $adminUser) !== '') ? trim((string) $adminUser) : 'root';
$adminPassEnv = getenv('ORANGE_PHASE1_MYSQL_ADMIN_PASS');
$adminPass = ($adminPassEnv !== false) ? (string) $adminPassEnv : '';

$runId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--run-id=')) {
        $runId = substr($arg, 9);
    }
}
if ($runId === null || $runId === '') {
    $runId = bin2hex(random_bytes(6));
}
if (!preg_match('/^[a-zA-Z0-9]{6,32}$/', $runId)) {
    fwrite(STDERR, "INVALID_RUN_ID\n");
    exit(2);
}
$runIdLower = strtolower($runId);

function phase1_assert_local_host(string $host): void
{
    $h = strtolower(trim($host));
    if (!in_array($h, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "NON_LOCAL_HOST_REJECTED\n");
        echo "LOCAL_DATABASE_HOST_PROVEN=0\n";
        echo "PRODUCTION_DATABASE_HOST_USED=1\n";
        echo "REMOTE_DATABASE_HOST_USED=1\n";
        exit(3);
    }
}

function phase1_sql_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
        throw new RuntimeException('bad_ident');
    }

    return '`' . $name . '`';
}

function phase1_sql_string(string $value): string
{
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
}

function phase1_random_password(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '._'), '=');
}

function phase1_grant_columns(PDO $sa, string $dbIdent, string $userHost, string $table, array $cols): void
{
    $list = implode(',', $cols);
    $sa->exec("GRANT SELECT ({$list}) ON {$dbIdent}.`{$table}` TO {$userHost}");
}

/**
 * SQL_MODE canonicalization: split/trim/upper/drop-empty/sort/join.
 */
function phase1_canonicalize_sql_mode(string $raw): string
{
    $parts = explode(',', $raw);
    $out = [];
    foreach ($parts as $p) {
        $t = strtoupper(trim($p));
        if ($t !== '') {
            $out[] = $t;
        }
    }
    sort($out, SORT_STRING);

    return implode(',', $out);
}

/**
 * ACTION_STATEMENT canonicalization: CRLF/CR → LF only (no token removal).
 */
function phase1_canonicalize_action_statement(string $stmt): string
{
    return str_replace(["\r\n", "\r"], "\n", $stmt);
}

function phase1_lowercase_meta(string $v): string
{
    return strtolower($v);
}

/**
 * Encode one canonical metadata field (Owner §8):
 * name + 0x1F + (0x00 if NULL | decimal_len + ':' + utf8 bytes) + LF
 */
function phase1_encode_trigger_meta_field(string $name, ?string $value): string
{
    $sep = "\x1F";
    if ($value === null) {
        return $name . $sep . "\x00" . "\n";
    }

    return $name . $sep . strlen($value) . ':' . $value . "\n";
}

/**
 * Canonical SHA-256 over fixed field order 1–14 (Owner §8). Aggregate is evidence only.
 *
 * @param array<string, mixed> $rec
 */
function phase1_stock_trigger_canonical_sha256(array $rec): string
{
    $order = [
        'trigger_name',
        'event_manipulation',
        'event_object_schema',
        'event_object_table',
        'action_order',
        'action_condition',
        'action_statement',
        'action_orientation',
        'action_timing',
        'sql_mode_canonical',
        'definer',
        'character_set_client',
        'collation_connection',
        'database_collation',
    ];
    $buf = '';
    foreach ($order as $field) {
        if (!array_key_exists($field, $rec)) {
            throw new RuntimeException('stock_trigger_full_metadata_restore_failed');
        }
        $raw = $rec[$field];
        if ($raw === null) {
            $buf .= phase1_encode_trigger_meta_field($field, null);
            continue;
        }
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            throw new RuntimeException('stock_trigger_full_metadata_restore_failed');
        }
        $buf .= phase1_encode_trigger_meta_field($field, (string) $raw);
    }

    return hash('sha256', $buf);
}

function phase1_show_create_trigger_sql(PDO $sa, string $name): string
{
    $show = $sa->query('SHOW CREATE TRIGGER ' . phase1_sql_ident($name))->fetch(PDO::FETCH_ASSOC);
    if (!is_array($show)) {
        return '';
    }
    foreach ($show as $k => $v) {
        if (stripos((string) $k, 'sql original statement') !== false
            || strcasecmp((string) $k, 'Create Trigger') === 0
            || strcasecmp((string) $k, 'Statement') === 0) {
            return (string) $v;
        }
    }
    if (isset($show['SQL Original Statement'])) {
        return (string) $show['SQL Original Statement'];
    }
    foreach ($show as $v) {
        if (is_string($v) && stripos($v, 'CREATE') !== false && stripos($v, 'TRIGGER') !== false) {
            return $v;
        }
    }

    return '';
}

/**
 * Read full stock-trigger metadata record from information_schema (+ SHOW CREATE).
 *
 * @return array<string, mixed>
 */
function phase1_read_stock_trigger_metadata(PDO $sa, string $controlDb, string $name): array
{
    $meta = $sa->prepare(
        'SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_SCHEMA, EVENT_OBJECT_TABLE,
                ACTION_ORDER, ACTION_CONDITION, ACTION_STATEMENT, ACTION_ORIENTATION, ACTION_TIMING,
                SQL_MODE, DEFINER, CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DATABASE_COLLATION
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ?
         LIMIT 1'
    );
    $meta->execute([$controlDb, $name]);
    $row = $meta->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('stock_trigger_missing_after_restore:' . $name);
    }
    $createSql = phase1_show_create_trigger_sql($sa, $name);
    if ($createSql === '' || stripos($createSql, 'CREATE') === false) {
        throw new RuntimeException('stock_trigger_show_create_missing_after_restore:' . $name);
    }

    $sqlModeRaw = (string) ($row['SQL_MODE'] ?? '');
    // ACTION_CONDITION: absent → explicit NULL (NULL and '' are distinct).
    $actionCondition = array_key_exists('ACTION_CONDITION', $row) ? $row['ACTION_CONDITION'] : null;
    if ($actionCondition !== null) {
        $actionCondition = (string) $actionCondition;
    }
    // ACTION_ORDER: when physically available as int/string; otherwise explicit NULL.
    $actionOrder = array_key_exists('ACTION_ORDER', $row) ? $row['ACTION_ORDER'] : null;
    if ($actionOrder === null || $actionOrder === '') {
        $actionOrderEncoded = null;
    } else {
        $actionOrderEncoded = (string) $actionOrder;
    }

    $actionStatement = phase1_canonicalize_action_statement((string) ($row['ACTION_STATEMENT'] ?? ''));
    $rec = [
        'trigger_name' => (string) ($row['TRIGGER_NAME'] ?? $name),
        'event_manipulation' => (string) ($row['EVENT_MANIPULATION'] ?? ''),
        'event_object_schema' => (string) ($row['EVENT_OBJECT_SCHEMA'] ?? ''),
        'event_object_table' => (string) ($row['EVENT_OBJECT_TABLE'] ?? ''),
        'action_order' => $actionOrderEncoded,
        'action_condition' => $actionCondition,
        'action_statement' => $actionStatement,
        'action_orientation' => (string) ($row['ACTION_ORIENTATION'] ?? ''),
        'action_timing' => (string) ($row['ACTION_TIMING'] ?? ''),
        'sql_mode_raw' => $sqlModeRaw,
        'sql_mode_canonical' => phase1_canonicalize_sql_mode($sqlModeRaw),
        'definer' => (string) ($row['DEFINER'] ?? ''),
        'character_set_client' => phase1_lowercase_meta((string) ($row['CHARACTER_SET_CLIENT'] ?? '')),
        'collation_connection' => phase1_lowercase_meta((string) ($row['COLLATION_CONNECTION'] ?? '')),
        'database_collation' => phase1_lowercase_meta((string) ($row['DATABASE_COLLATION'] ?? '')),
        'show_create_trigger_sql' => $createSql,
        'create_sql' => $createSql,
    ];
    foreach (
        [
            'trigger_name', 'event_manipulation', 'event_object_schema', 'event_object_table',
            'action_statement', 'action_orientation', 'action_timing', 'sql_mode_raw',
            'sql_mode_canonical', 'definer', 'character_set_client', 'collation_connection',
            'database_collation', 'show_create_trigger_sql',
        ] as $req
    ) {
        if (!array_key_exists($req, $rec) || $rec[$req] === '') {
            throw new RuntimeException('stock_trigger_full_metadata_restore_failed');
        }
    }
    $rec['sha256'] = phase1_stock_trigger_canonical_sha256($rec);

    return $rec;
}

/**
 * Capture stock Rev4 BEFORE UPDATE triggers (full metadata + canonical SHA-256).
 *
 * @return array<string, array<string, mixed>>
 */
function phase1_capture_stock_bu_triggers(PDO $sa, string $controlDb): array
{
    $names = ['trg_ctrl_registry_bu_phase21a', 'trg_ctrl_countries_bu_phase21a'];
    $out = [];
    foreach ($names as $name) {
        try {
            $out[$name] = phase1_read_stock_trigger_metadata($sa, $controlDb, $name);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'stock_trigger_missing_after_restore:')) {
                throw new RuntimeException('stock_trigger_missing_before_capture:' . $name);
            }
            throw $e;
        }
    }

    return $out;
}

function phase1_drop_stock_bu_triggers_only(PDO $sa): void
{
    $sa->exec('DROP TRIGGER IF EXISTS trg_ctrl_registry_bu_phase21a');
    $sa->exec('DROP TRIGGER IF EXISTS trg_ctrl_countries_bu_phase21a');
}

/**
 * Apply session SQL_MODE / charset / collation so CREATE TRIGGER stores matching metadata.
 *
 * @param array<string, mixed> $cap
 */
function phase1_apply_session_for_stock_trigger_restore(PDO $sa, array $cap): void
{
    $sqlMode = (string) ($cap['sql_mode_raw'] ?? '');
    $sa->exec('SET SESSION sql_mode = ' . phase1_sql_string($sqlMode));
    $cs = (string) ($cap['character_set_client'] ?? '');
    $cc = (string) ($cap['collation_connection'] ?? '');
    if ($cs !== '') {
        $sa->exec('SET SESSION character_set_client = ' . phase1_sql_string($cs));
    }
    if ($cc !== '') {
        $sa->exec('SET SESSION collation_connection = ' . phase1_sql_string($cc));
    }
}

/**
 * Recreate EXACT captured stock UPDATE triggers. Fails closed on any metadata mismatch.
 *
 * @param array<string, array<string, mixed>> $caps
 * @param array<string, mixed>|null $expectedMetaAuthority pre-mutation authority (Owner fail-closed)
 */
function phase1_restore_exact_stock_bu_triggers(
    PDO $sa,
    string $controlDb,
    array $caps,
    ?array $expectedMetaAuthority = null
): void {
    phase1_drop_stock_bu_triggers_only($sa);
    // Ensure no permanent gate-aware replacements linger.
    $sa->exec('DROP TRIGGER IF EXISTS trg_ctrl_registry_bu_phase1_gate');
    $sa->exec('DROP TRIGGER IF EXISTS trg_ctrl_countries_bu_phase1_gate');
    foreach (['trg_ctrl_registry_bu_phase21a', 'trg_ctrl_countries_bu_phase21a'] as $name) {
        if (!isset($caps[$name]['create_sql']) || !is_string($caps[$name]['create_sql']) || $caps[$name]['create_sql'] === '') {
            throw new RuntimeException('stock_trigger_restore_missing_capture:' . $name);
        }
        phase1_apply_session_for_stock_trigger_restore($sa, $caps[$name]);
        $sa->exec($caps[$name]['create_sql']);
    }
    phase1_verify_stock_bu_triggers($sa, $controlDb, $caps);
    phase1_verify_control_meta_gate($sa, $expectedMetaAuthority);
}

/**
 * Fresh ctrl_schema_meta authority snapshot (no ownership_token / ownership_run_id).
 *
 * @return array{
 *   control_schema_revision:int,
 *   schema_manifest_hash:string,
 *   country_active_transition_enabled:int,
 *   registry_active_transition_enabled:int,
 *   database_replacement_transition_enabled:int
 * }
 */
function phase1_read_control_meta_authority(PDO $sa): array
{
    $row = $sa->query(
        'SELECT control_schema_revision, schema_manifest_hash,
                country_active_transition_enabled, registry_active_transition_enabled,
                database_replacement_transition_enabled
         FROM ctrl_schema_meta WHERE id = 1 LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('meta_authority_read_fail');
    }

    return [
        'control_schema_revision' => (int) ($row['control_schema_revision'] ?? 0),
        'schema_manifest_hash' => (string) ($row['schema_manifest_hash'] ?? ''),
        'country_active_transition_enabled' => (int) ($row['country_active_transition_enabled'] ?? 0),
        'registry_active_transition_enabled' => (int) ($row['registry_active_transition_enabled'] ?? 0),
        'database_replacement_transition_enabled' => (int) ($row['database_replacement_transition_enabled'] ?? 0),
    ];
}

/**
 * Stable SHA-256 over meta authority fields (Owner binding; no credentials/tokens).
 *
 * @param array<string, mixed> $meta
 */
function phase1_control_meta_authority_hash(array $meta): string
{
    $canonical = implode("\x1F", [
        'control_schema_revision=' . (int) ($meta['control_schema_revision'] ?? 0),
        'schema_manifest_hash=' . (string) ($meta['schema_manifest_hash'] ?? ''),
        'country_active_transition_enabled=' . (int) ($meta['country_active_transition_enabled'] ?? 0),
        'registry_active_transition_enabled=' . (int) ($meta['registry_active_transition_enabled'] ?? 0),
        'database_replacement_transition_enabled=' . (int) ($meta['database_replacement_transition_enabled'] ?? 0),
    ]);

    return hash('sha256', $canonical, false);
}

/**
 * Fresh aggregate hash of both stock BU trigger canonical SHA-256 digests.
 */
function phase1_aggregate_stock_trigger_hash(PDO $sa, string $controlDb): string
{
    $parts = [];
    foreach (['trg_ctrl_registry_bu_phase21a', 'trg_ctrl_countries_bu_phase21a'] as $name) {
        $now = phase1_read_stock_trigger_metadata($sa, $controlDb, $name);
        $parts[] = $name . '=' . (string) ($now['sha256'] ?? '');
    }

    return hash('sha256', implode('|', $parts), false);
}

/**
 * Control Meta / gate state must remain intact after restore (fail-closed).
 * When $expectedAuthority is provided, compare against pre-mutation capture.
 *
 * @param array<string, mixed>|null $expectedAuthority
 */
function phase1_verify_control_meta_gate(PDO $sa, ?array $expectedAuthority = null): void
{
    $row = phase1_read_control_meta_authority($sa);
    if ((int) ($row['control_schema_revision'] ?? 0) !== (int) ORANGE_CONTROL_SCHEMA_REVISION) {
        throw new RuntimeException('meta_gate_restore_mismatch');
    }
    if ((int) ($row['country_active_transition_enabled'] ?? 0) !== 1
        || (int) ($row['registry_active_transition_enabled'] ?? 0) !== 1) {
        throw new RuntimeException('meta_gate_restore_mismatch');
    }
    if ($expectedAuthority !== null) {
        $expHash = phase1_control_meta_authority_hash($expectedAuthority);
        $actHash = phase1_control_meta_authority_hash($row);
        if (!hash_equals($expHash, $actHash)) {
            throw new RuntimeException('meta_authority_mismatch_after_restore');
        }
    }
}

/**
 * In-memory restoration-verification receipt (INVALID/NULL until this returns VALID).
 * Order: stock verify (fresh) → meta verify vs pre-mutation authority → bind hashes.
 *
 * @param array<string, array<string, mixed>> $caps
 * @param array<string, mixed> $expectedMetaAuthority
 * @return array{
 *   status:string,
 *   run_id:string,
 *   control_db:string,
 *   trigger_hash:string,
 *   expected_meta_hash:string,
 *   actual_meta_hash:string,
 *   verified_at_utc:string
 * }
 */
function phase1_issue_restoration_verification_receipt(
    PDO $sa,
    string $controlDb,
    array $caps,
    array $expectedMetaAuthority,
    string $runId
): array {
    phase1_verify_stock_bu_triggers($sa, $controlDb, $caps);
    $actualMeta = phase1_read_control_meta_authority($sa);
    phase1_verify_control_meta_gate($sa, $expectedMetaAuthority);
    $triggerHash = phase1_aggregate_stock_trigger_hash($sa, $controlDb);
    $expectedMetaHash = phase1_control_meta_authority_hash($expectedMetaAuthority);
    $actualMetaHash = phase1_control_meta_authority_hash($actualMeta);
    if (!hash_equals($expectedMetaHash, $actualMetaHash)) {
        throw new RuntimeException('meta_authority_mismatch_after_restore');
    }
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

    return [
        'status' => 'VALID',
        'run_id' => $runId,
        'control_db' => $controlDb,
        'trigger_hash' => $triggerHash,
        'expected_meta_hash' => $expectedMetaHash,
        'actual_meta_hash' => $actualMetaHash,
        'verified_at_utc' => $now,
    ];
}

/**
 * Final success gate: receipt must be VALID and match a fresh DB re-read.
 *
 * @param array<string, mixed>|null $receipt
 * @param array<string, array<string, mixed>> $caps
 * @param array<string, mixed> $expectedMetaAuthority
 */
function phase1_revalidate_restoration_receipt(
    ?array $receipt,
    PDO $sa,
    string $controlDb,
    array $caps,
    array $expectedMetaAuthority,
    string $runId
): void {
    if (!is_array($receipt) || ($receipt['status'] ?? '') !== 'VALID') {
        throw new RuntimeException('restoration_receipt_invalid');
    }
    if ((string) ($receipt['run_id'] ?? '') !== $runId
        || (string) ($receipt['control_db'] ?? '') !== $controlDb) {
        throw new RuntimeException('restoration_receipt_binding_mismatch');
    }
    phase1_verify_stock_bu_triggers($sa, $controlDb, $caps);
    $actualMeta = phase1_read_control_meta_authority($sa);
    phase1_verify_control_meta_gate($sa, $expectedMetaAuthority);
    $triggerHash = phase1_aggregate_stock_trigger_hash($sa, $controlDb);
    $expectedMetaHash = phase1_control_meta_authority_hash($expectedMetaAuthority);
    $actualMetaHash = phase1_control_meta_authority_hash($actualMeta);
    if (!hash_equals((string) ($receipt['trigger_hash'] ?? ''), $triggerHash)
        || !hash_equals((string) ($receipt['expected_meta_hash'] ?? ''), $expectedMetaHash)
        || !hash_equals((string) ($receipt['actual_meta_hash'] ?? ''), $actualMetaHash)
        || !hash_equals($expectedMetaHash, $actualMetaHash)) {
        throw new RuntimeException('restoration_receipt_stale');
    }
}

/**
 * Per-field compare with stable failure codes (Owner §9). Aggregate hash is evidence only.
 *
 * @param array<string, array<string, mixed>> $caps
 */
function phase1_verify_stock_bu_triggers(PDO $sa, string $controlDb, array $caps): void
{
    $gateCount = (int) $sa->query(
        'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=' . phase1_sql_string($controlDb)
        . " AND TRIGGER_NAME IN ('trg_ctrl_registry_bu_phase1_gate','trg_ctrl_countries_bu_phase1_gate')"
    )->fetchColumn();
    if ($gateCount !== 0) {
        throw new RuntimeException('phase1_gate_trigger_present_after_restore');
    }
    foreach (['trg_ctrl_registry_bu_phase21a', 'trg_ctrl_countries_bu_phase21a'] as $name) {
        if (!isset($caps[$name]) || !is_array($caps[$name])) {
            throw new RuntimeException('stock_trigger_full_metadata_restore_failed');
        }
        $exp = $caps[$name];
        try {
            $now = phase1_read_stock_trigger_metadata($sa, $controlDb, $name);
        } catch (RuntimeException $e) {
            throw $e;
        }
        $createSql = (string) ($now['show_create_trigger_sql'] ?? '');
        if (stripos($createSql, 'phase1_gate') !== false) {
            throw new RuntimeException('stock_trigger_contains_phase1_gate:' . $name);
        }
        if (stripos($createSql, 'phase21c_prerequisites_not_available') === false
            && stripos((string) ($now['action_statement'] ?? ''), 'phase21c_prerequisites_not_available') === false) {
            throw new RuntimeException('stock_trigger_missing_prereq_signal:' . $name);
        }

        // Event / body / orientation / timing
        if ((string) ($exp['event_manipulation'] ?? '') !== (string) ($now['event_manipulation'] ?? '')
            || (string) ($exp['event_object_schema'] ?? '') !== (string) ($now['event_object_schema'] ?? '')
            || (string) ($exp['event_object_table'] ?? '') !== (string) ($now['event_object_table'] ?? '')
            || (string) ($exp['action_orientation'] ?? '') !== (string) ($now['action_orientation'] ?? '')
            || (string) ($exp['action_timing'] ?? '') !== (string) ($now['action_timing'] ?? '')
            || (($exp['action_order'] ?? null) !== ($now['action_order'] ?? null))) {
            throw new RuntimeException('stock_trigger_event_contract_mismatch_after_restore:' . $name);
        }
        $expCond = array_key_exists('action_condition', $exp) ? $exp['action_condition'] : null;
        $nowCond = array_key_exists('action_condition', $now) ? $now['action_condition'] : null;
        if ($expCond !== $nowCond) {
            throw new RuntimeException('stock_trigger_event_contract_mismatch_after_restore:' . $name);
        }
        if ((string) ($exp['action_statement'] ?? '') !== (string) ($now['action_statement'] ?? '')) {
            throw new RuntimeException('stock_trigger_body_mismatch_after_restore:' . $name);
        }
        // DEFINER: exact, no strip / no role normalization
        if ((string) ($exp['definer'] ?? '') === '') {
            throw new RuntimeException('stock_trigger_definer_empty:' . $name);
        }
        if ((string) ($exp['definer'] ?? '') !== (string) ($now['definer'] ?? '')) {
            throw new RuntimeException('stock_trigger_definer_mismatch_after_restore:' . $name);
        }
        if ((string) ($exp['sql_mode_canonical'] ?? '') !== (string) ($now['sql_mode_canonical'] ?? '')) {
            throw new RuntimeException('stock_trigger_sql_mode_mismatch_after_restore:' . $name);
        }
        if ((string) ($exp['character_set_client'] ?? '') !== (string) ($now['character_set_client'] ?? '')) {
            throw new RuntimeException('stock_trigger_character_set_client_mismatch_after_restore:' . $name);
        }
        if ((string) ($exp['collation_connection'] ?? '') !== (string) ($now['collation_connection'] ?? '')) {
            throw new RuntimeException('stock_trigger_collation_connection_mismatch_after_restore:' . $name);
        }
        if ((string) ($exp['database_collation'] ?? '') !== (string) ($now['database_collation'] ?? '')) {
            throw new RuntimeException('stock_trigger_database_collation_mismatch_after_restore:' . $name);
        }
        // Aggregate hash evidence (must match after per-field inventory)
        if (!hash_equals((string) ($exp['sha256'] ?? ''), (string) ($now['sha256'] ?? ''))) {
            throw new RuntimeException('stock_trigger_full_metadata_restore_failed');
        }
    }
}

// Allow self-tests to require helpers without executing bootstrap main.
if (defined('ORANGE_PHASE1_BOOTSTRAP_AS_LIB') && ORANGE_PHASE1_BOOTSTRAP_AS_LIB) {
    return;
}

phase1_assert_local_host($host);

// Disposable Phase1 may activate registry/country for fixtures only.
if (!defined('ORANGE_CONTROL_COUNTRY_ACTIVE_TRANSITION_ENABLED')) {
    define('ORANGE_CONTROL_COUNTRY_ACTIVE_TRANSITION_ENABLED', 1);
}
if (!defined('ORANGE_CONTROL_REGISTRY_ACTIVE_TRANSITION_ENABLED')) {
    define('ORANGE_CONTROL_REGISTRY_ACTIVE_TRANSITION_ENABLED', 1);
}

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/includes/control_schema.php';
require_once $repoRoot . '/includes/control_fingerprint.php';

$controlDb = 'orange_phase1_control_' . $runIdLower;
$kwDb = 'orange_phase1_kw_' . $runIdLower;
$egDb = 'orange_phase1_eg_' . $runIdLower;
$histDb = 'orange_phase1_hist_' . $runIdLower;

$saUser = 'p1sa_' . $runIdLower;
$rtCtrlUser = 'p1crt_' . $runIdLower;
$kwUser = 'p1kw_' . $runIdLower;
$egUser = 'p1eg_' . $runIdLower;

$saPass = phase1_random_password();
$rtCtrlPass = phase1_random_password();
$kwPass = phase1_random_password();
$egPass = phase1_random_password();

$kwSecretRef = 'orange://secrets/country_runtime/kw/' . $runIdLower;
$egSecretRef = 'orange://secrets/country_runtime/eg/' . $runIdLower;
$ctrlSecretRef = 'orange://secrets/control_runtime/' . $runIdLower;
$kwPrincipal = 'orange://principals/country_runtime/kw/' . $runIdLower;
$egPrincipal = 'orange://principals/country_runtime/eg/' . $runIdLower;
$ctrlPrincipal = 'orange://principals/control_runtime/' . $runIdLower;

$kwUuid = '11111111-1111-4111-8111-111111111111';
$egUuid = '33333333-3333-4333-8333-333333333333';
$xxUuid = '55555555-5555-4555-8555-555555555555';
$kwDbUuid = '22222222-2222-4222-8222-222222222222';
$egDbUuid = '44444444-4444-4444-8444-444444444444';
$xxDbUuid = '66666666-6666-4666-8666-666666666666';

$templateHash = hash('sha256', 'orange_phase1_template_v1', false);
$ownershipToken = hash('sha256', random_bytes(32), false);
$hostIdents = ['localhost', '127.0.0.1'];

$tempDir = sys_get_temp_dir();
$statePath = $tempDir . DIRECTORY_SEPARATOR . 'orange_phase1_state_' . $runId . '.json';
$secretsPath = $tempDir . DIRECTORY_SEPARATOR . 'orange_phase1_secrets_' . $runId . '.json';

$createdUsers = [];
$createdDbs = [];

try {
    $adminDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
    $admin = new PDO($adminDsn, $adminUser, $adminPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $ver = (string) $admin->query('SELECT VERSION()')->fetchColumn();
    echo "LOCAL_DATABASE_HOST_PROVEN=1\n";
    echo "PRODUCTION_DATABASE_HOST_USED=0\n";
    echo "REMOTE_DATABASE_HOST_USED=0\n";
    echo 'MYSQL_VERSION_LABEL=' . preg_replace('/[^A-Za-z0-9._-]/', '', $ver) . "\n";

    $orangeExists = (int) $admin->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='orange_db'"
    )->fetchColumn();

    // Ensure DEFINER routines can be created under binary logging.
    $binlogTrust = 0;
    try {
        $admin->exec('SET GLOBAL log_bin_trust_function_creators = 1');
        $binlogTrust = 1;
    } catch (Throwable $e) {
        $binlogTrust = 0;
    }

    foreach ([$controlDb, $kwDb, $egDb] as $db) {
        $admin->exec('CREATE DATABASE ' . phase1_sql_ident($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $createdDbs[] = $db;
    }

    // schema_admin user (both hosts)
    foreach ($hostIdents as $hi) {
        $admin->exec(
            'CREATE USER ' . phase1_sql_string($saUser) . '@' . phase1_sql_string($hi)
            . ' IDENTIFIED BY ' . phase1_sql_string($saPass)
        );
        $createdUsers[] = [$saUser, $hi];
        $u = phase1_sql_string($saUser) . '@' . phase1_sql_string($hi);
        $cdb = phase1_sql_ident($controlDb);
        $admin->exec("GRANT ALL PRIVILEGES ON {$cdb}.* TO {$u} WITH GRANT OPTION");
        $admin->exec("GRANT TRIGGER, CREATE ROUTINE, ALTER ROUTINE, EXECUTE ON {$cdb}.* TO {$u}");
        foreach ([$kwDb, $egDb] as $cdbName) {
            $admin->exec('GRANT ALL PRIVILEGES ON ' . phase1_sql_ident($cdbName) . '.* TO ' . $u . ' WITH GRANT OPTION');
        }
        $admin->exec('GRANT CREATE USER ON *.* TO ' . $u);
        $admin->exec('GRANT RELOAD ON *.* TO ' . $u);
    }
    $admin->exec('FLUSH PRIVILEGES');

    $sa = new PDO($adminDsn, $saUser, $saPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sa->exec('USE ' . phase1_sql_ident($controlDb));

    if ($binlogTrust !== 1) {
        // Attempt install as schema_admin anyway; many local MySQL installs allow it.
    }

    orange_control_ensure_schema($sa, [
        'ownership_token' => $ownershipToken,
        'ownership_run_id' => $runIdLower,
        'installed_by' => 'phase1_router_repair_bootstrap',
        'allow_meta_insert' => true,
    ]);

    // Ensure gates are ON on disposable meta (DATA only).
    $sa->exec(
        'UPDATE ctrl_schema_meta SET
            country_active_transition_enabled = 1,
            registry_active_transition_enabled = 1
         WHERE id = 1'
    );

    orange_control_seed_reserved_names($sa, [
        $controlDb => 'control',
        'orange_db' => 'legacy_orange',
        'mysql' => 'system',
        'information_schema' => 'system',
        'performance_schema' => 'system',
        'sys' => 'system',
        'orange_phase1_reserved_tok' => 'phase1_reserved_deny_proof',
    ]);

    // Capture stock Rev4 UPDATE triggers BEFORE any temporary seed-window mutation.
    // Permanent phase1_gate replacement is forbidden (PHASE1_GATE_TRIGGER_CREATE_COUNT=0).
    $stockBuCaps = phase1_capture_stock_bu_triggers($sa, $controlDb);
    $phase1GateTriggerCreateCount = 0;
    $stockTriggerRestoreOk = 0;
    // Receipt INVALID/NULL until full restore verification (Owner F10 fail-closed).
    $restorationReceipt = null;
    // Capture expected Meta authority BEFORE mutation window.
    $expectedMetaAuthority = phase1_read_control_meta_authority($sa);

    // Host profiles: loopback literal 127.0.0.1 + localhost + staging/prod env_map fixtures
    $sa->exec(
        "INSERT INTO ctrl_db_host_profiles (
            profile_key, environment, display_name, allowed_host_literal, host_resolution_mode,
            env_host_key, env_port_key, default_port, require_tls, verify_server_cert,
            connect_timeout_sec, profile_status, created_at, updated_at
         ) VALUES
         ('local_loopback_127','local','Local 127','127.0.0.1','literal',NULL,NULL,{$port},0,0,5,'active',NOW(6),NOW(6)),
         ('local_loopback_localhost','local','Local localhost','localhost','literal',NULL,NULL,{$port},0,0,5,'active',NOW(6),NOW(6)),
         ('staging_env_map','staging','Staging env','','env_map','ORANGE_PHASE1_STAGING_HOST','ORANGE_PHASE1_STAGING_PORT',{$port},0,0,5,'active',NOW(6),NOW(6)),
         ('production_env_map','production','Prod env','','env_map','ORANGE_PHASE1_PROD_HOST','ORANGE_PHASE1_PROD_PORT',{$port},0,0,5,'active',NOW(6),NOW(6))"
    );
    $profiles = $sa->query(
        "SELECT id, profile_key FROM ctrl_db_host_profiles WHERE profile_key IN (
            'local_loopback_127','local_loopback_localhost','staging_env_map','production_env_map'
         )"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    // fetchAll KEY_PAIR returns id=>profile_key; invert
    $profileByKey = [];
    foreach ($sa->query('SELECT id, profile_key FROM ctrl_db_host_profiles')->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $profileByKey[(string) $pr['profile_key']] = (int) $pr['id'];
    }
    $hostProfileId = $profileByKey['local_loopback_127'];

    // Countries KW / EG / XX(inactive draft for unknown/inactive tests)
    $sa->exec(
        "INSERT INTO ctrl_countries (
            country_uuid, code, slug, name_ar, name_en, currency_code, timezone,
            lifecycle_status, sort_order, created_at, updated_at
         ) VALUES
         ('{$kwUuid}','kw','kuwait','الكويت','Kuwait','KWD','Asia/Kuwait','draft',1,NOW(6),NOW(6)),
         ('{$egUuid}','eg','egypt','مصر','Egypt','EGP','Africa/Cairo','draft',2,NOW(6),NOW(6)),
         ('{$xxUuid}','xx','inactive-x','غير نشط','Inactive X','XXX','UTC','draft',99,NOW(6),NOW(6))"
    );
    $countryIds = [];
    foreach ($sa->query('SELECT id, code FROM ctrl_countries')->fetchAll(PDO::FETCH_ASSOC) as $cr) {
        $countryIds[strtoupper((string) $cr['code'])] = (int) $cr['id'];
    }

    // Seed non-active fixtures WITH stock triggers still installed.
    // Countries stay draft; registry stays verifying until the short SA seed window.

    // Secrets + principals
    $sa->exec(
        "INSERT INTO ctrl_secret_refs (
            secret_ref, provider, purpose, country_id, current_version, previous_version,
            rotation_state, created_at
         ) VALUES
         (" . $sa->quote($kwSecretRef) . ",'file','country_runtime',{$countryIds['KW']},1,NULL,'active',NOW(6)),
         (" . $sa->quote($egSecretRef) . ",'file','country_runtime',{$countryIds['EG']},1,NULL,'active',NOW(6)),
         (" . $sa->quote($ctrlSecretRef) . ",'file','control_runtime',NULL,1,NULL,'active',NOW(6))"
    );

    $sa->exec(
        "INSERT INTO ctrl_runtime_principals (
            principal_ref, mysql_user, host_pattern, country_id, role_kind, secret_ref,
            principal_status, created_at
         ) VALUES
         (" . $sa->quote($kwPrincipal) . "," . $sa->quote($kwUser) . ",'127.0.0.1',{$countryIds['KW']},'country_runtime',"
        . $sa->quote($kwSecretRef) . ",'active',NOW(6)),
         (" . $sa->quote($egPrincipal) . "," . $sa->quote($egUser) . ",'127.0.0.1',{$countryIds['EG']},'country_runtime',"
        . $sa->quote($egSecretRef) . ",'active',NOW(6)),
         (" . $sa->quote($ctrlPrincipal) . "," . $sa->quote($rtCtrlUser) . ",'127.0.0.1',NULL,'control_runtime',"
        . $sa->quote($ctrlSecretRef) . ",'active',NOW(6))"
    );

    // Do NOT call lifecycle/trust active transitions under stock BU triggers.
    // Active rows are applied only inside the temporary schema-admin seed window below.

    $kwFp = orange_control_fingerprint_v1_hash([
        'fingerprint_version' => 1,
        'country_uuid' => $kwUuid,
        'database_uuid' => $kwDbUuid,
        'host_profile_key' => 'local_loopback_127',
        'db_name' => $kwDb,
        'installed_schema_revision' => $schemaRevision,
        'schema_template_hash' => $templateHash,
    ]);
    $egFp = orange_control_fingerprint_v1_hash([
        'fingerprint_version' => 1,
        'country_uuid' => $egUuid,
        'database_uuid' => $egDbUuid,
        'host_profile_key' => 'local_loopback_127',
        'db_name' => $egDb,
        'installed_schema_revision' => $schemaRevision,
        'schema_template_hash' => $templateHash,
    ]);

    $kwRegId = orange_control_register_mapping($sa, [
        'database_uuid' => $kwDbUuid,
        'country_id' => $countryIds['KW'],
        'country_uuid' => $kwUuid,
        'db_key' => 'dbkey_kw_' . $runIdLower,
        'db_name' => $kwDb,
        'host_profile_id' => $hostProfileId,
        'secret_ref' => $kwSecretRef,
        'secret_version' => 1,
        'runtime_principal_ref' => $kwPrincipal,
        'required_schema_revision' => $schemaRevision,
        'installed_schema_revision' => $schemaRevision,
        'schema_template_hash' => $templateHash,
        'identity_fingerprint' => $kwFp,
        'registry_status' => 'verifying',
    ]);
    $egRegId = orange_control_register_mapping($sa, [
        'database_uuid' => $egDbUuid,
        'country_id' => $countryIds['EG'],
        'country_uuid' => $egUuid,
        'db_key' => 'dbkey_eg_' . $runIdLower,
        'db_name' => $egDb,
        'host_profile_id' => $hostProfileId,
        'secret_ref' => $egSecretRef,
        'secret_version' => 1,
        'runtime_principal_ref' => $egPrincipal,
        'required_schema_revision' => $schemaRevision,
        'installed_schema_revision' => $schemaRevision,
        'schema_template_hash' => $templateHash,
        'identity_fingerprint' => $egFp,
        'registry_status' => 'verifying',
    ]);

    // Historical other-country deny proof: register XX as verifying then retire with hist db_name.
    // Non-active transitions remain valid under stock BU triggers (NEW.status != active).
    $xxSecret = 'orange://secrets/country_runtime/xx/' . $runIdLower;
    $xxPrincipal = 'orange://principals/country_runtime/xx/' . $runIdLower;
    $sa->exec(
        "INSERT INTO ctrl_secret_refs (secret_ref, provider, purpose, country_id, current_version, rotation_state, created_at)
         VALUES (" . $sa->quote($xxSecret) . ",'file','country_runtime',{$countryIds['XX']},1,'active',NOW(6))"
    );
    $sa->exec(
        "INSERT INTO ctrl_runtime_principals (
            principal_ref, mysql_user, host_pattern, country_id, role_kind, secret_ref, principal_status, created_at
         ) VALUES (
            " . $sa->quote($xxPrincipal) . ",'p1xx_" . $runIdLower . "','127.0.0.1',{$countryIds['XX']},'country_runtime',"
        . $sa->quote($xxSecret) . ",'revoked',NOW(6))"
    );
    $xxFp = orange_control_fingerprint_v1_hash([
        'fingerprint_version' => 1,
        'country_uuid' => $xxUuid,
        'database_uuid' => $xxDbUuid,
        'host_profile_key' => 'local_loopback_127',
        'db_name' => $histDb,
        'installed_schema_revision' => $schemaRevision,
        'schema_template_hash' => $templateHash,
    ]);
    $xxRegId = orange_control_register_mapping($sa, [
        'database_uuid' => $xxDbUuid,
        'country_id' => $countryIds['XX'],
        'country_uuid' => $xxUuid,
        'db_key' => 'dbkey_xx_' . $runIdLower,
        'db_name' => $histDb,
        'host_profile_id' => $hostProfileId,
        'secret_ref' => $xxSecret,
        'secret_version' => 1,
        'runtime_principal_ref' => $xxPrincipal,
        'required_schema_revision' => $schemaRevision,
        'installed_schema_revision' => $schemaRevision,
        'schema_template_hash' => $templateHash,
        'identity_fingerprint' => $xxFp,
        'registry_status' => 'allocating',
    ]);
    // Transition allocating → suspended → retired to leave history ANY-status claim.
    $rv = 1;
    $t = orange_control_registry_trust_transition($sa, $xxRegId, $rv, ['registry_status' => 'suspended'], 'phase1_hist_suspend');
    $rv = (int) $t['row_version'];
    orange_control_registry_trust_transition($sa, $xxRegId, $rv, ['registry_status' => 'retired'], 'phase1_hist_retire');

    // Short schema-admin-only seed window: drop ONLY stock BU triggers, mark fixtures active,
    // then ALWAYS restore exact stock UPDATE triggers before any runtime users / router use.
    try {
        phase1_drop_stock_bu_triggers_only($sa);
        $sa->prepare(
            'UPDATE ctrl_countries SET lifecycle_status = ?, activated_at = NOW(6), updated_at = NOW(6) WHERE id = ?'
        )->execute(['active', $countryIds['KW']]);
        $sa->prepare(
            'UPDATE ctrl_countries SET lifecycle_status = ?, activated_at = NOW(6), updated_at = NOW(6) WHERE id = ?'
        )->execute(['active', $countryIds['EG']]);
        $sa->prepare(
            'UPDATE ctrl_country_db_registry
             SET registry_status = ?, row_version = row_version + 1, activated_at = NOW(6), updated_at = NOW(6)
             WHERE id = ?'
        )->execute(['active', $kwRegId]);
        $sa->prepare(
            'UPDATE ctrl_country_db_registry
             SET registry_status = ?, row_version = row_version + 1, activated_at = NOW(6), updated_at = NOW(6)
             WHERE id = ?'
        )->execute(['active', $egRegId]);
    } finally {
        // Fail-closed: verify throws → receipt stays NULL; STOCK_TRIGGER_RESTORE_OK stays 0.
        phase1_restore_exact_stock_bu_triggers($sa, $controlDb, $stockBuCaps, $expectedMetaAuthority);
    }
    // Owner §7 success order: issue in-memory receipt only after restore + fresh verifies.
    $restorationReceipt = phase1_issue_restoration_verification_receipt(
        $sa,
        $controlDb,
        $stockBuCaps,
        $expectedMetaAuthority,
        $runId
    );
    if (($restorationReceipt['status'] ?? '') !== 'VALID') {
        throw new RuntimeException('restoration_receipt_invalid');
    }
    $stockTriggerRestoreOk = 1;

    // Create control_runtime + country_runtime MySQL users on both hosts
    // (ONLY after stock trigger restoration — never during the seed window.)
    foreach (
        [
            [$rtCtrlUser, $rtCtrlPass],
            [$kwUser, $kwPass],
            [$egUser, $egPass],
        ] as [$u, $p]
    ) {
        foreach ($hostIdents as $hi) {
            $sa->exec(
                'CREATE USER ' . phase1_sql_string($u) . '@' . phase1_sql_string($hi)
                . ' IDENTIFIED BY ' . phase1_sql_string($p)
            );
            $createdUsers[] = [$u, $hi];
        }
    }

    $dbIdent = phase1_sql_ident($controlDb);

    // Exact X3 positive column grants for control_runtime (DML=0)
    $grantSets = [
        'ctrl_countries' => [
            'id','country_uuid','code','slug','name_ar','name_en','name_fil','name_hi',
            'currency_code','timezone','locale','default_language','phone_dial_code',
            'decimal_policy_ref','sort_order','lifecycle_status','is_active',
            'activated_at','suspended_at','created_at','updated_at',
            'created_by_admin_id','updated_by_admin_id',
        ],
        'ctrl_db_host_profiles' => [
            'id','profile_key','environment','display_name','allowed_host_literal',
            'host_resolution_mode','env_host_key','env_port_key','default_port',
            'require_tls','verify_server_cert','connect_timeout_sec','profile_status',
            'created_at','updated_at',
        ],
        'ctrl_reserved_database_names' => ['db_name','reason','created_at'],
        'ctrl_secret_refs' => [
            'secret_ref','provider','purpose','country_id','current_version',
            'previous_version','rotation_state','rotated_at','created_at',
        ],
        'ctrl_runtime_principals' => [
            'principal_ref','mysql_user','host_pattern','country_id','role_kind',
            'secret_ref','principal_status','is_active','created_at',
        ],
        'ctrl_country_db_registry' => [
            'id','database_uuid','country_id','country_uuid','db_key','db_name',
            'host_profile_id','port_override','secret_ref','secret_version',
            'runtime_principal_ref','required_schema_revision','installed_schema_revision',
            'schema_template_hash','identity_fingerprint','registry_status','is_routable',
            'active_host_db_slot','secret_ref_slot','principal_ref_slot',
            'health_status','failure_code','last_health_at','last_verified_at',
            'activated_at','row_version','created_at','updated_at',
            'created_by_admin_id','updated_by_admin_id',
        ],
        'ctrl_country_db_registry_history' => [
            'history_id','registry_id','country_id','country_uuid','database_uuid',
            'db_key','db_name','host_profile_id','host_profile_key','secret_ref',
            'secret_version','runtime_principal_ref','required_schema_revision',
            'installed_schema_revision','schema_template_hash','identity_fingerprint',
            'old_registry_status','new_registry_status','row_version_before',
            'row_version_after','change_reason','changed_at','changed_by_admin_id',
            'snapshot_hash',
        ],
        'ctrl_schema_meta' => [
            'id','control_schema_revision','schema_manifest_hash','installed_at','installed_by',
            'country_active_transition_enabled','registry_active_transition_enabled',
            'database_replacement_transition_enabled',
        ],
        // Owner X3 / §9: NO control_runtime SELECT on uuid_ledger / audit_events / trust_events
        // (localhost and 127.0.0.1). Positive-column grants only on the tables above.
    ];

    foreach ($hostIdents as $hi) {
        $uh = phase1_sql_string($rtCtrlUser) . '@' . phase1_sql_string($hi);
        foreach ($grantSets as $table => $cols) {
            phase1_grant_columns($sa, $dbIdent, $uh, $table, $cols);
        }
    }

    $sa->exec('FLUSH PRIVILEGES');

    // Country DB identity + fixture (schema_admin) — create tables BEFORE grants
    foreach (
        [
            [$kwDb, 'KW', $kwUuid, $kwDbUuid, $kwFp, $kwPrincipal, 'KW_FIXTURE_ONLY'],
            [$egDb, 'EG', $egUuid, $egDbUuid, $egFp, $egPrincipal, 'EG_FIXTURE_ONLY'],
        ] as [$cdb, $code, $cuuid, $duuid, $fp, $sealedBy, $marker]
    ) {
        $sa->exec('USE ' . phase1_sql_ident($cdb));
        $sa->exec(
            'CREATE TABLE orange_db_identity (
                identity_row_id TINYINT UNSIGNED NOT NULL,
                database_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                country_uuid CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                country_code VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                schema_revision INT NOT NULL,
                schema_template_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                identity_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                host_profile_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                db_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                sealed_at DATETIME(6) NOT NULL,
                sealed_by_principal_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                identity_revision INT UNSIGNED NOT NULL,
                fingerprint_version INT UNSIGNED NOT NULL,
                PRIMARY KEY (identity_row_id),
                UNIQUE KEY uq_odi_database_uuid (database_uuid),
                UNIQUE KEY uq_odi_country_uuid (country_uuid),
                CONSTRAINT chk_odi_row CHECK (identity_row_id = 1),
                CONSTRAINT chk_odi_identity_revision CHECK (identity_revision = 1),
                CONSTRAINT chk_odi_fingerprint_version CHECK (fingerprint_version = 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $sa->exec(
            'CREATE TABLE orange_phase1_fixture (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                country_code VARCHAR(8) NOT NULL,
                marker VARCHAR(64) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $insId = $sa->prepare(
            'INSERT INTO orange_db_identity (
                identity_row_id, database_uuid, country_uuid, country_code, schema_revision,
                schema_template_hash, identity_fingerprint, host_profile_key, db_name,
                sealed_at, sealed_by_principal_ref, identity_revision, fingerprint_version
             ) VALUES (1,?,?,?,?,?,?,?,?,NOW(6),?,1,1)'
        );
        $insId->execute([
            $duuid, $cuuid, $code, $schemaRevision, $templateHash, $fp,
            'local_loopback_127', $cdb, $sealedBy,
        ]);
        $insFx = $sa->prepare('INSERT INTO orange_phase1_fixture (country_code, marker) VALUES (?, ?)');
        $insFx->execute([$code, $marker]);
    }

    // Country runtime grants (tables now exist)
    foreach (
        [
            [$kwUser, $kwDb],
            [$egUser, $egDb],
        ] as [$u, $cdb]
    ) {
        $cident = phase1_sql_ident($cdb);
        foreach ($hostIdents as $hi) {
            $uh = phase1_sql_string($u) . '@' . phase1_sql_string($hi);
            $sa->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON {$cident}.`orange_phase1_fixture` TO {$uh}");
            $sa->exec("GRANT SELECT ON {$cident}.`orange_db_identity` TO {$uh}");
        }
    }
    $sa->exec('FLUSH PRIVILEGES');

    $secrets = [
        'by_ref_version' => [
            $kwSecretRef . '|1' => $kwPass,
            $egSecretRef . '|1' => $egPass,
            $ctrlSecretRef . '|1' => $rtCtrlPass,
        ],
        'control_runtime_password' => $rtCtrlPass,
        'schema_admin_password' => $saPass,
    ];
    $secretsJson = json_encode($secrets, JSON_UNESCAPED_SLASHES);
    if ($secretsJson === false || file_put_contents($secretsPath, $secretsJson) === false) {
        throw new RuntimeException('secrets_write_fail');
    }

    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
    $state = [
        'run_id' => $runId,
        'schema_revision' => $schemaRevision,
        'control_schema_revision' => ORANGE_CONTROL_SCHEMA_REVISION,
        'host' => '127.0.0.1',
        'port' => $port,
        'control_db' => $controlDb,
        'kw_db' => $kwDb,
        'eg_db' => $egDb,
        'hist_db_name' => $histDb,
        'kw_user' => $kwUser,
        'eg_user' => $egUser,
        'schema_admin_user' => $saUser,
        'control_runtime_user' => $rtCtrlUser,
        'kw_secret_ref' => $kwSecretRef,
        'eg_secret_ref' => $egSecretRef,
        'control_secret_ref' => $ctrlSecretRef,
        'kw_principal_ref' => $kwPrincipal,
        'eg_principal_ref' => $egPrincipal,
        'control_principal_ref' => $ctrlPrincipal,
        'kw_database_uuid' => $kwDbUuid,
        'eg_database_uuid' => $egDbUuid,
        'kw_country_uuid' => $kwUuid,
        'eg_country_uuid' => $egUuid,
        'kw_registry_id' => $kwRegId,
        'eg_registry_id' => $egRegId,
        'xx_registry_id' => $xxRegId,
        'host_profile_id_127' => $hostProfileId,
        'host_profile_id_localhost' => $profileByKey['local_loopback_localhost'],
        'host_profile_id_staging' => $profileByKey['staging_env_map'],
        'host_profile_id_production' => $profileByKey['production_env_map'],
        'schema_template_hash' => $templateHash,
        'kw_identity_fingerprint' => $kwFp,
        'eg_identity_fingerprint' => $egFp,
        'secrets_path' => $secretsPath,
        'state_path' => $statePath,
        'orange_db_existed_at_bootstrap' => $orangeExists,
        'created_at_utc' => $now,
        'binlog_trust' => $binlogTrust,
        'stock_bu_triggers' => $stockBuCaps,
        'phase1_gate_trigger_create_count' => $phase1GateTriggerCreateCount,
        'stock_trigger_restore_ok' => $stockTriggerRestoreOk,
        'restoration_receipt_status' => is_array($restorationReceipt) ? (string) ($restorationReceipt['status'] ?? 'INVALID') : 'INVALID',
        'restoration_receipt_trigger_hash' => is_array($restorationReceipt) ? (string) ($restorationReceipt['trigger_hash'] ?? '') : '',
        'restoration_receipt_expected_meta_hash' => is_array($restorationReceipt) ? (string) ($restorationReceipt['expected_meta_hash'] ?? '') : '',
        'restoration_receipt_actual_meta_hash' => is_array($restorationReceipt) ? (string) ($restorationReceipt['actual_meta_hash'] ?? '') : '',
        'restoration_receipt_verified_at_utc' => is_array($restorationReceipt) ? (string) ($restorationReceipt['verified_at_utc'] ?? '') : '',
    ];
    $stateJson = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($stateJson === false || file_put_contents($statePath, $stateJson) === false) {
        throw new RuntimeException('state_write_fail');
    }

    // Final success gate: revalidate receipt + fresh DB before any success exposure.
    // Country fixture seeding may have changed session database — pin Control first.
    $sa->exec('USE ' . phase1_sql_ident($controlDb));
    phase1_revalidate_restoration_receipt(
        $restorationReceipt,
        $sa,
        $controlDb,
        $stockBuCaps,
        $expectedMetaAuthority,
        $runId
    );
    if ($stockTriggerRestoreOk !== 1 || $phase1GateTriggerCreateCount !== 0) {
        throw new RuntimeException('stock_trigger_restore_gate_fail');
    }
    if (!is_array($restorationReceipt) || ($restorationReceipt['status'] ?? '') !== 'VALID') {
        throw new RuntimeException('restoration_receipt_invalid');
    }

    echo "BOOTSTRAP_OK=1\n";
    echo 'RUN_ID=' . $runId . "\n";
    echo 'CONTROL_DB=' . $controlDb . "\n";
    echo 'KW_DB=' . $kwDb . "\n";
    echo 'EG_DB=' . $egDb . "\n";
    echo 'KW_USER=' . $kwUser . "\n";
    echo 'EG_USER=' . $egUser . "\n";
    echo 'SCHEMA_ADMIN_USER=' . $saUser . "\n";
    echo 'CONTROL_RUNTIME_USER=' . $rtCtrlUser . "\n";
    echo 'CONTROL_SCHEMA_REVISION=' . ORANGE_CONTROL_SCHEMA_REVISION . "\n";
    echo "STATE_PATH_SET=1\n";
    echo "SECRETS_PATH_SET=1\n";
    echo "RAW_PASSWORD_IN_OUTPUT=0\n";
    echo 'PHASE1_GATE_TRIGGER_CREATE_COUNT=' . $phase1GateTriggerCreateCount . "\n";
    echo 'STOCK_TRIGGER_RESTORE_OK=' . $stockTriggerRestoreOk . "\n";
    echo 'STOCK_TRIGGER_SHA256_REGISTRY=' . $stockBuCaps['trg_ctrl_registry_bu_phase21a']['sha256'] . "\n";
    echo 'STOCK_TRIGGER_SHA256_COUNTRIES=' . $stockBuCaps['trg_ctrl_countries_bu_phase21a']['sha256'] . "\n";
    echo 'ORANGE_DB_UNTOUCHED=' . ($orangeExists === (int) $admin->query(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='orange_db'"
    )->fetchColumn() ? '1' : '0') . "\n";
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $msg = preg_replace('/password[^\\s]*/i', 'password=[REDACTED]', $msg) ?? 'error';
    fwrite(STDERR, 'BOOTSTRAP_FAIL=' . $msg . "\n");
    try {
        if (isset($admin) && $admin instanceof PDO) {
            foreach ($createdUsers as [$u, $hi]) {
                try {
                    $admin->exec('DROP USER IF EXISTS ' . phase1_sql_string($u) . '@' . phase1_sql_string($hi));
                } catch (Throwable $ignore) {
                }
            }
            foreach ($createdDbs as $db) {
                try {
                    $admin->exec('DROP DATABASE IF EXISTS ' . phase1_sql_ident($db));
                } catch (Throwable $ignore) {
                }
            }
            try {
                $admin->exec('FLUSH PRIVILEGES');
            } catch (Throwable $ignore) {
            }
        }
    } catch (Throwable $ignore) {
    }
    @unlink($secretsPath);
    @unlink($statePath);
    exit(1);
}
