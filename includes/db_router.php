<?php
declare(strict_types=1);

/**
 * Orange Phase-1 — dormant explicit DB router (Control DB + one Country DB).
 *
 * NOT included by Production runtime. Does not call, wrap, or replace db().
 * Local / contained proof only.
 *
 * Aligned to Control Trust-Core rev4 physical names (ctrl_*).
 * Timeout: prefer PDO::ATTR_TIMEOUT; else save/restore default_socket_timeout in finally.
 */

require_once __DIR__ . '/control_fingerprint.php';

final class OrangeDbRouterException extends RuntimeException
{
    /** @var string */
    private $errorCode;

    public function __construct(string $errorCode, string $message = '')
    {
        $this->errorCode = $errorCode;
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

/**
 * Request-local (instance-local) router. Separate instances do not share cache state.
 */
final class OrangeDbRouter
{
    /** @var array<string, PDO> */
    private $connections = [];

    /** @var string */
    private $controlDbName;

    /** @var string|null */
    private $controlCacheKey = null;

    public function __construct(string $controlDbName)
    {
        $name = trim($controlDbName);
        if ($name === '') {
            throw new OrangeDbRouterException('missing_control_db_name');
        }
        orange_db_router_validate_db_name($name);
        $this->controlDbName = $name;
    }

    public function controlDbName(): string
    {
        return $this->controlDbName;
    }

    /**
     * Open Control DB from supplied settings (injected; never reads Production db()).
     *
     * Expected keys: host, port, db_name, username, password, connect_timeout_sec?
     * Forbidden: request_host / client host override keys.
     *
     * @param array<string, mixed> $settings
     */
    public function openControl(array $settings): PDO
    {
        if (array_key_exists('request_host', $settings) || array_key_exists('client_host', $settings)) {
            throw new OrangeDbRouterException('request_supplied_host_denied');
        }

        $host = isset($settings['host']) ? trim((string) $settings['host']) : '';
        $port = isset($settings['port']) ? (int) $settings['port'] : 0;
        $dbName = isset($settings['db_name']) ? trim((string) $settings['db_name']) : '';
        $username = isset($settings['username']) ? (string) $settings['username'] : '';
        $password = isset($settings['password']) ? (string) $settings['password'] : '';
        $timeout = isset($settings['connect_timeout_sec']) ? (int) $settings['connect_timeout_sec'] : 5;
        if ($timeout < 1) {
            $timeout = 5;
        }

        if ($host === '') {
            throw new OrangeDbRouterException('missing_control_host');
        }
        orange_db_router_validate_host_port($host, $port);
        orange_db_router_assert_loopback_host($host);
        orange_db_router_validate_db_name($dbName);
        if (strcasecmp($dbName, $this->controlDbName) !== 0) {
            throw new OrangeDbRouterException('control_db_name_mismatch');
        }
        if ($username === '') {
            throw new OrangeDbRouterException('missing_control_username');
        }

        $cacheKey = orange_db_router_build_cache_key([
            'role' => 'control',
            'controlDbName' => $this->controlDbName,
            'country_code' => '',
            'country_uuid' => '',
            'database_uuid' => '',
            'db_key' => '',
            'db_name' => $dbName,
            'host_profile_id' => 0,
            'host_profile_key' => '',
            'environment' => 'local',
            'host' => $host,
            'port' => $port,
            'mysql_user' => $username,
            'runtime_principal_ref' => '',
            'secret_ref' => 'control://supplied',
            'secret_version' => 0,
            'identity_fingerprint' => '',
            'installed_schema_revision' => 0,
            'schema_template_hash' => '',
            'registry_status' => '',
            'required_schema_revision' => 0,
        ]);

        if (isset($this->connections[$cacheKey])) {
            return $this->connections[$cacheKey];
        }

        $pdo = orange_db_router_connect_pdo($host, $port, $dbName, $username, $password, $timeout);
        $this->connections[$cacheKey] = $pdo;
        $this->controlCacheKey = $cacheKey;

        return $pdo;
    }

    /**
     * Load one Country DB registry JOIN row by exact Country code (normalized).
     *
     * @return array<string, mixed>
     */
    public function loadCountryRegistryRow(PDO $control, string $countryCode): array
    {
        $code = orange_db_router_normalize_country_code($countryCode);

        $stmt = $control->prepare(
            'SELECT
                r.id AS registry_id,
                r.database_uuid,
                r.country_id,
                r.country_uuid,
                r.db_key,
                r.db_name,
                r.host_profile_id,
                r.port_override,
                r.secret_ref,
                r.secret_version,
                r.runtime_principal_ref,
                r.required_schema_revision,
                r.installed_schema_revision,
                r.schema_template_hash,
                r.identity_fingerprint,
                r.registry_status,
                r.is_routable,
                r.row_version,
                c.code AS country_code,
                c.lifecycle_status AS country_lifecycle_status,
                h.profile_key AS host_profile_key,
                h.environment,
                h.allowed_host_literal,
                h.host_resolution_mode,
                h.env_host_key,
                h.env_port_key,
                h.default_port,
                h.connect_timeout_sec,
                h.profile_status,
                p.principal_ref,
                p.mysql_user,
                p.host_pattern,
                p.country_id AS principal_country_id,
                p.role_kind,
                p.secret_ref AS principal_secret_ref,
                p.principal_status,
                s.provider AS secret_provider,
                s.purpose AS secret_purpose,
                s.country_id AS secret_country_id,
                s.current_version AS secret_current_version,
                s.previous_version AS secret_previous_version,
                s.rotation_state
             FROM ctrl_country_db_registry r
             INNER JOIN ctrl_countries c ON c.id = r.country_id
             INNER JOIN ctrl_db_host_profiles h ON h.id = r.host_profile_id
             INNER JOIN ctrl_runtime_principals p ON p.principal_ref = r.runtime_principal_ref
             INNER JOIN ctrl_secret_refs s ON s.secret_ref = r.secret_ref
             WHERE UPPER(c.code) = ?
             LIMIT 1'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new OrangeDbRouterException('unknown_country', 'unknown_country:' . $code);
        }

        return $row;
    }

    /**
     * Resolve one active Country DB registry entry (fail-closed).
     *
     * @return array<string, mixed>
     */
    public function resolveCountryDb(PDO $control, string $countryCode): array
    {
        $row = $this->loadCountryRegistryRow($control, $countryCode);
        $code = orange_db_router_normalize_country_code((string) ($row['country_code'] ?? ''));

        if (strtolower((string) ($row['registry_status'] ?? '')) !== 'active'
            || (int) ($row['is_routable'] ?? 0) !== 1
        ) {
            throw new OrangeDbRouterException('inactive_country');
        }
        if (strtolower((string) ($row['country_lifecycle_status'] ?? '')) !== 'active') {
            throw new OrangeDbRouterException('inactive_country');
        }
        if (strtolower((string) ($row['profile_status'] ?? '')) !== 'active') {
            throw new OrangeDbRouterException('host_profile_inactive');
        }

        $dbName = trim((string) ($row['db_name'] ?? ''));
        orange_db_router_validate_db_name($dbName);
        $this->assertAbsoluteCountryDbDeny($control, $dbName, (int) ($row['country_id'] ?? 0));

        $principalStatus = strtolower(trim((string) ($row['principal_status'] ?? '')));
        if ($principalStatus === '') {
            throw new OrangeDbRouterException('missing_runtime_principal');
        }
        if ($principalStatus !== 'active') {
            throw new OrangeDbRouterException('inactive_runtime_principal');
        }
        if (strtolower(trim((string) ($row['role_kind'] ?? ''))) !== 'country_runtime') {
            throw new OrangeDbRouterException('principal_role_mismatch');
        }
        if ((int) ($row['principal_country_id'] ?? 0) !== (int) ($row['country_id'] ?? 0)) {
            throw new OrangeDbRouterException('principal_country_mismatch');
        }

        $regSecret = strtolower(trim((string) ($row['secret_ref'] ?? '')));
        $prinSecret = strtolower(trim((string) ($row['principal_secret_ref'] ?? '')));
        if ($regSecret === '' || $prinSecret === '') {
            throw new OrangeDbRouterException('missing_secret_ref');
        }
        if (strcasecmp($regSecret, $prinSecret) !== 0) {
            throw new OrangeDbRouterException('principal_secret_divergence');
        }
        if ((int) ($row['secret_country_id'] ?? 0) !== (int) ($row['country_id'] ?? 0)) {
            throw new OrangeDbRouterException('secret_country_mismatch');
        }

        $secretVersion = (int) ($row['secret_version'] ?? 0);
        $currentVersion = (int) ($row['secret_current_version'] ?? 0);
        $previousVersion = $row['secret_previous_version'] === null ? null : (int) $row['secret_previous_version'];
        $rotation = strtolower(trim((string) ($row['rotation_state'] ?? '')));
        if ($rotation === 'retired') {
            throw new OrangeDbRouterException('secret_version_mismatch');
        }
        if ($rotation === 'overlapping') {
            $ok = ($secretVersion === $currentVersion)
                || ($previousVersion !== null && $secretVersion === $previousVersion);
            if (!$ok) {
                throw new OrangeDbRouterException('secret_version_mismatch');
            }
        } elseif ($rotation === 'active') {
            if ($secretVersion !== $currentVersion) {
                throw new OrangeDbRouterException('secret_version_mismatch');
            }
        } else {
            throw new OrangeDbRouterException('secret_version_mismatch');
        }

        $mysqlUser = trim((string) ($row['mysql_user'] ?? ''));
        if ($mysqlUser === '') {
            throw new OrangeDbRouterException('missing_runtime_principal');
        }
        $dbKey = trim((string) ($row['db_key'] ?? ''));
        if ($dbKey !== '' && strcasecmp($dbKey, $mysqlUser) === 0) {
            // db_key may equal mysql_user by fixture design; binding still uses mysql_user only.
        }

        $resolved = orange_db_router_resolve_host_from_profile($row);
        $timeout = (int) ($row['connect_timeout_sec'] ?? 5);
        if ($timeout < 1) {
            $timeout = 5;
        }

        $requiredRev = (int) ($row['required_schema_revision'] ?? 0);
        if ($requiredRev < 1) {
            throw new OrangeDbRouterException('invalid_required_schema_revision');
        }

        $row['country_code'] = $code;
        $row['db_name'] = $dbName;
        $row['resolved_host'] = $resolved['host'];
        $row['resolved_port'] = $resolved['port'];
        $row['connect_timeout_sec'] = $timeout;
        $row['mysql_user'] = $mysqlUser;
        $row['secret_ref'] = $regSecret;
        $row['secret_version'] = $secretVersion;
        $row['required_schema_revision'] = $requiredRev;
        $row['host_pattern'] = trim((string) ($row['host_pattern'] ?? ''));

        return $row;
    }

    /**
     * Open one Country DB using registry + injected secret resolver.
     *
     * Secret resolver signature: function(string $secretRef, int $secretVersion): ?string
     *
     * @param callable(string,int):(?string) $secretResolver
     */
    public function openCountryDb(PDO $control, string $countryCode, callable $secretResolver): PDO
    {
        // X6: fresh Control re-resolution BEFORE cache lookup.
        $row = $this->resolveCountryDb($control, $countryCode);

        $secretRef = (string) $row['secret_ref'];
        $secretVersion = (int) $row['secret_version'];
        $resolved = $secretResolver($secretRef, $secretVersion);
        if ($resolved === null || $resolved === '') {
            throw new OrangeDbRouterException('missing_resolved_secret');
        }

        $mysqlUser = (string) $row['mysql_user'];
        $dbKey = trim((string) ($row['db_key'] ?? ''));
        if ($dbKey !== '' && strcasecmp($mysqlUser, $dbKey) !== 0) {
            // Explicit: never use db_key as MySQL username.
        }

        $cacheKey = orange_db_router_build_cache_key([
            'role' => 'country',
            'controlDbName' => $this->controlDbName,
            'country_code' => (string) $row['country_code'],
            'country_uuid' => (string) $row['country_uuid'],
            'database_uuid' => (string) $row['database_uuid'],
            'db_key' => (string) $row['db_key'],
            'db_name' => (string) $row['db_name'],
            'host_profile_id' => (int) $row['host_profile_id'],
            'host_profile_key' => (string) $row['host_profile_key'],
            'environment' => (string) $row['environment'],
            'host' => (string) $row['resolved_host'],
            'port' => (int) $row['resolved_port'],
            'mysql_user' => $mysqlUser,
            'runtime_principal_ref' => (string) $row['runtime_principal_ref'],
            'secret_ref' => $secretRef,
            'secret_version' => $secretVersion,
            'identity_fingerprint' => (string) $row['identity_fingerprint'],
            'installed_schema_revision' => (int) ($row['installed_schema_revision'] ?? 0),
            'schema_template_hash' => (string) $row['schema_template_hash'],
            'registry_status' => (string) $row['registry_status'],
            'required_schema_revision' => (int) $row['required_schema_revision'],
        ]);

        if (isset($this->connections[$cacheKey])) {
            $pdo = $this->connections[$cacheKey];
            $this->validateCountryIdentity($pdo, $row);
            $this->assertCurrentUserBinding($pdo, $mysqlUser, (string) $row['host_pattern']);

            return $pdo;
        }

        try {
            $pdo = orange_db_router_connect_pdo(
                (string) $row['resolved_host'],
                (int) $row['resolved_port'],
                (string) $row['db_name'],
                $mysqlUser,
                (string) $resolved,
                (int) $row['connect_timeout_sec']
            );
        } catch (OrangeDbRouterException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new OrangeDbRouterException('connection_failure', 'connection_failure');
        }

        $this->assertCurrentUserBinding($pdo, $mysqlUser, (string) $row['host_pattern']);
        $this->validateCountryIdentity($pdo, $row);
        $this->connections[$cacheKey] = $pdo;

        return $pdo;
    }

    /**
     * Validate Country/schema identity markers on an opened Country connection (steps 1–13).
     *
     * @param array<string, mixed> $registryRow
     */
    public function validateCountryIdentity(PDO $countryPdo, array $registryRow): void
    {
        $expectedDbName = trim((string) ($registryRow['db_name'] ?? ''));
        $code = orange_db_router_normalize_country_code((string) ($registryRow['country_code'] ?? ''));
        orange_db_router_validate_db_name($expectedDbName);

        // 1) SELECT DATABASE()
        $currentDb = (string) $countryPdo->query('SELECT DATABASE()')->fetchColumn();
        if ($currentDb === '' || strcasecmp($currentDb, $expectedDbName) !== 0) {
            throw new OrangeDbRouterException('registry_db_name_mismatch');
        }

        // 2) COUNT(*) then 3) identity_row_id = 1 (FORBIDDEN: LIMIT 1 without COUNT)
        $count = (int) $countryPdo->query('SELECT COUNT(*) FROM orange_db_identity')->fetchColumn();
        if ($count === 0) {
            throw new OrangeDbRouterException('missing_country_identity_marker');
        }
        if ($count > 1) {
            throw new OrangeDbRouterException('identity_duplicate');
        }

        $stmt = $countryPdo->query(
            'SELECT identity_row_id, database_uuid, country_uuid, country_code, schema_revision,
                    schema_template_hash, identity_fingerprint, host_profile_key, db_name,
                    sealed_at, sealed_by_principal_ref, identity_revision, fingerprint_version
             FROM orange_db_identity
             WHERE identity_row_id = 1'
        );
        $marker = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($marker)) {
            throw new OrangeDbRouterException('missing_country_identity_marker');
        }

        // Versioned Country-identity contract gates (before fingerprint dispatch).
        // Fail-closed: no NULL-as-1, no string cast acceptance, no fallback to v1.
        $identityRevision = filter_var($marker['identity_revision'] ?? null, FILTER_VALIDATE_INT);
        if ($identityRevision !== 1) {
            throw new OrangeDbRouterException('identity_revision_unsupported');
        }
        $fingerprintVersion = filter_var($marker['fingerprint_version'] ?? null, FILTER_VALIDATE_INT);
        if ($fingerprintVersion !== 1) {
            throw new OrangeDbRouterException('fingerprint_version_unsupported');
        }

        // 4) country_code
        $markerCode = strtoupper(trim((string) ($marker['country_code'] ?? '')));
        if ($markerCode === '' || strlen($markerCode) > 8) {
            throw new OrangeDbRouterException('identity_country_code_mismatch');
        }
        if ($markerCode !== $code) {
            throw new OrangeDbRouterException('identity_country_code_mismatch');
        }

        // 5) country_uuid
        if (strcasecmp((string) ($marker['country_uuid'] ?? ''), (string) ($registryRow['country_uuid'] ?? '')) !== 0) {
            throw new OrangeDbRouterException('identity_country_mismatch');
        }

        // 6) database_uuid
        if (strcasecmp((string) ($marker['database_uuid'] ?? ''), (string) ($registryRow['database_uuid'] ?? '')) !== 0) {
            throw new OrangeDbRouterException('identity_database_uuid_mismatch');
        }

        // 7) db_name
        if (strcasecmp((string) ($marker['db_name'] ?? ''), $expectedDbName) !== 0) {
            throw new OrangeDbRouterException('country_identity_mismatch');
        }

        // 8) host_profile_key
        if (strcasecmp((string) ($marker['host_profile_key'] ?? ''), (string) ($registryRow['host_profile_key'] ?? '')) !== 0) {
            throw new OrangeDbRouterException('country_identity_mismatch');
        }

        // 9) schema_revision vs installed_schema_revision
        if ($registryRow['installed_schema_revision'] === null || $registryRow['installed_schema_revision'] === '') {
            throw new OrangeDbRouterException('schema_revision_mismatch');
        }
        $installed = (int) $registryRow['installed_schema_revision'];
        $rev = (int) ($marker['schema_revision'] ?? 0);
        if ($rev !== $installed) {
            throw new OrangeDbRouterException('schema_revision_mismatch');
        }

        // 10) schema_template_hash
        if (strcasecmp((string) ($marker['schema_template_hash'] ?? ''), (string) ($registryRow['schema_template_hash'] ?? '')) !== 0) {
            throw new OrangeDbRouterException('schema_template_hash_mismatch');
        }

        // 11) recompute fingerprint (v1 helper only after version gates pass)
        try {
            $fields = [
                'fingerprint_version' => 1,
                'country_uuid' => (string) $registryRow['country_uuid'],
                'database_uuid' => (string) $registryRow['database_uuid'],
                'host_profile_key' => (string) $registryRow['host_profile_key'],
                'db_name' => strtolower($expectedDbName),
                'installed_schema_revision' => $installed,
                'schema_template_hash' => (string) $registryRow['schema_template_hash'],
            ];
            orange_control_fingerprint_v1_assert_match($fields, (string) ($marker['identity_fingerprint'] ?? ''));
        } catch (OrangeControlTrustException $e) {
            throw new OrangeDbRouterException('identity_fingerprint_mismatch');
        }

        // 12) stored identity_fingerprint vs registry
        if (!hash_equals(
            strtolower((string) ($registryRow['identity_fingerprint'] ?? '')),
            strtolower((string) ($marker['identity_fingerprint'] ?? ''))
        )) {
            throw new OrangeDbRouterException('identity_registry_divergence');
        }

        // 13) sealed fields present
        if (trim((string) ($marker['sealed_at'] ?? '')) === ''
            || trim((string) ($marker['sealed_by_principal_ref'] ?? '')) === ''
        ) {
            throw new OrangeDbRouterException('identity_unregistered_clone');
        }
    }

    /**
     * Legacy helper signature retained for tests that pass discrete expected fields.
     */
    public function validateCountrySchema(
        PDO $countryPdo,
        string $expectedCountryCode,
        int $requiredSchemaRevision,
        string $expectedDbName
    ): void {
        // Build a minimal row for DATABASE() mismatch / code checks only.
        $this->validateCountryIdentity($countryPdo, [
            'country_code' => $expectedCountryCode,
            'db_name' => $expectedDbName,
            'country_uuid' => '',
            'database_uuid' => '',
            'host_profile_key' => '',
            'installed_schema_revision' => $requiredSchemaRevision,
            'schema_template_hash' => str_repeat('0', 64),
            'identity_fingerprint' => str_repeat('0', 64),
        ]);
    }

    private function assertCurrentUserBinding(PDO $pdo, string $mysqlUser, string $hostPattern): void
    {
        $cu = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        $parts = explode('@', $cu, 2);
        $user = $parts[0] ?? '';
        $host = $parts[1] ?? '';
        if (strcasecmp($user, $mysqlUser) !== 0) {
            throw new OrangeDbRouterException('principal_host_mismatch');
        }
        if (!orange_db_router_host_pattern_matches($host, $hostPattern)) {
            throw new OrangeDbRouterException('principal_host_mismatch');
        }
    }

    /**
     * X1 absolute deny: control/orange_db/system/reserved + other-country registry AND history
     * ANY status including retired. No retired-name release.
     */
    private function assertAbsoluteCountryDbDeny(PDO $control, string $dbName, int $countryId): void
    {
        if (strcasecmp($dbName, $this->controlDbName) === 0) {
            throw new OrangeDbRouterException('control_db_cannot_be_country_db');
        }
        if (strcasecmp($dbName, 'orange_db') === 0) {
            throw new OrangeDbRouterException('forbidden_db_name');
        }
        $sys = ['mysql', 'information_schema', 'performance_schema', 'sys'];
        foreach ($sys as $s) {
            if (strcasecmp($dbName, $s) === 0) {
                throw new OrangeDbRouterException('system_database_name_denied');
            }
        }

        $rs = $control->prepare(
            'SELECT COUNT(*) FROM ctrl_reserved_database_names WHERE LOWER(db_name) = LOWER(?)'
        );
        $rs->execute([$dbName]);
        if ((int) $rs->fetchColumn() > 0) {
            throw new OrangeDbRouterException('reserved_database_name_denied');
        }

        $q1 = $control->prepare(
            'SELECT COUNT(*) FROM ctrl_country_db_registry
             WHERE country_id <> ? AND LOWER(db_name) = LOWER(?)'
        );
        $q1->execute([$countryId, $dbName]);
        if ((int) $q1->fetchColumn() > 0) {
            throw new OrangeDbRouterException('forbidden_other_country_db_name');
        }

        $q2 = $control->prepare(
            'SELECT COUNT(*) FROM ctrl_country_db_registry_history
             WHERE country_id <> ? AND LOWER(db_name) = LOWER(?)'
        );
        $q2->execute([$countryId, $dbName]);
        if ((int) $q2->fetchColumn() > 0) {
            throw new OrangeDbRouterException('forbidden_other_country_db_name');
        }
    }

    public function resetConnections(): void
    {
        foreach ($this->connections as $pdo) {
            unset($pdo);
        }
        $this->connections = [];
        $this->controlCacheKey = null;
    }

    /**
     * @return list<array{key_fingerprint:string,role:string}>
     */
    public function cacheInventory(): array
    {
        $out = [];
        foreach (array_keys($this->connections) as $key) {
            $parts = explode('|', $key);
            $role = $parts[0] ?? 'unknown';
            $out[] = [
                'key_fingerprint' => substr(hash('sha256', $key), 0, 16),
                'role' => $role,
            ];
        }

        return $out;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }
}

/** @return OrangeDbRouter */
function orange_db_router_new(string $controlDbName): OrangeDbRouter
{
    return new OrangeDbRouter($controlDbName);
}

/**
 * Normalize Country code: trim + uppercase; reject empty / non [A-Za-z]{1,8}.
 */
function orange_db_router_normalize_country_code(string $countryCode): string
{
    $code = trim($countryCode);
    if ($code === '') {
        throw new OrangeDbRouterException('empty_country_code');
    }
    if (!preg_match('/^[A-Za-z]{1,8}$/', $code)) {
        throw new OrangeDbRouterException('invalid_country_code');
    }

    return strtoupper($code);
}

function orange_db_router_validate_db_name(string $dbName): void
{
    $name = trim($dbName);
    if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
        throw new OrangeDbRouterException('malformed_db_name');
    }
    if (strcasecmp($name, 'orange_db') === 0) {
        throw new OrangeDbRouterException('forbidden_db_name');
    }
}

function orange_db_router_validate_host_port(string $host, int $port): void
{
    $h = trim($host);
    if ($h === '' || strlen($h) > 255) {
        throw new OrangeDbRouterException('malformed_host');
    }
    if (!preg_match('/^[A-Za-z0-9._\\-:]+$/', $h)) {
        throw new OrangeDbRouterException('malformed_host');
    }
    if ($port < 1 || $port > 65535) {
        throw new OrangeDbRouterException('malformed_port');
    }
}

function orange_db_router_is_loopback_host(string $host): bool
{
    $h = strtolower(trim($host));

    return in_array($h, ['127.0.0.1', 'localhost', '::1'], true);
}

function orange_db_router_assert_loopback_host(string $host): void
{
    if (!orange_db_router_is_loopback_host($host)) {
        throw new OrangeDbRouterException('non_loopback_host_denied');
    }
}

/**
 * @param array<string, mixed> $profileRow
 * @return array{host:string,port:int}
 */
function orange_db_router_resolve_host_from_profile(array $profileRow): array
{
    if (array_key_exists('request_host', $profileRow) || array_key_exists('client_host', $profileRow)) {
        throw new OrangeDbRouterException('request_supplied_host_denied');
    }

    $env = strtolower(trim((string) ($profileRow['environment'] ?? '')));
    $mode = strtolower(trim((string) ($profileRow['host_resolution_mode'] ?? '')));
    $portOverride = $profileRow['port_override'] ?? null;
    $defaultPort = (int) ($profileRow['default_port'] ?? 3306);

    $host = '';
    $port = $defaultPort;

    if ($mode === 'literal') {
        if (in_array($env, ['staging', 'production'], true)) {
            throw new OrangeDbRouterException('host_resolution_mode_unsupported');
        }
        $host = trim((string) ($profileRow['allowed_host_literal'] ?? ''));
        if ($host === '') {
            throw new OrangeDbRouterException('missing_host_literal');
        }
    } elseif ($mode === 'env_map') {
        $hk = trim((string) ($profileRow['env_host_key'] ?? ''));
        $pk = trim((string) ($profileRow['env_port_key'] ?? ''));
        if ($hk === '') {
            throw new OrangeDbRouterException('missing_host_env');
        }
        $hv = getenv($hk);
        $host = ($hv !== false) ? trim((string) $hv) : '';
        if ($host === '') {
            if ($env === 'staging') {
                throw new OrangeDbRouterException('staging_host_env_required');
            }
            if ($env === 'production') {
                throw new OrangeDbRouterException('production_host_env_required');
            }
            throw new OrangeDbRouterException('missing_host_env');
        }
        if ($pk !== '') {
            $pv = getenv($pk);
            if ($pv === false || trim((string) $pv) === '') {
                if ($env === 'staging') {
                    throw new OrangeDbRouterException('staging_host_env_required');
                }
                if ($env === 'production') {
                    throw new OrangeDbRouterException('production_host_env_required');
                }
                throw new OrangeDbRouterException('missing_port_env');
            }
            $port = (int) $pv;
        }
    } else {
        throw new OrangeDbRouterException('host_resolution_mode_unsupported');
    }

    if ($portOverride !== null && $portOverride !== '') {
        $port = (int) $portOverride;
    }

    orange_db_router_validate_host_port($host, $port);

    if (in_array($env, ['local', 'test'], true) && !orange_db_router_is_loopback_host($host)) {
        throw new OrangeDbRouterException('non_loopback_host_denied');
    }

    return ['host' => $host, 'port' => $port];
}

function orange_db_router_host_pattern_matches(string $currentHost, string $pattern): bool
{
    $p = trim($pattern);
    $h = trim($currentHost);
    if ($p === '' || $h === '') {
        return false;
    }
    if ($p === '%') {
        return true;
    }
    if (strcasecmp($p, $h) === 0) {
        return true;
    }
    // Simple MySQL-like single-segment wildcard.
    $regex = '/^' . str_replace(['\\%', '\\_'], ['.*', '.'], preg_quote($p, '/')) . '$/i';

    return (bool) preg_match($regex, $h);
}

/**
 * @param array<string, mixed> $parts
 */
function orange_db_router_build_cache_key(array $parts): string
{
    $order = [
        'role',
        'controlDbName',
        'country_code',
        'country_uuid',
        'database_uuid',
        'db_key',
        'db_name',
        'host_profile_id',
        'host_profile_key',
        'environment',
        'host',
        'port',
        'mysql_user',
        'runtime_principal_ref',
        'secret_ref',
        'secret_version',
        'identity_fingerprint',
        'installed_schema_revision',
        'schema_template_hash',
        'registry_status',
        'required_schema_revision',
    ];
    $bits = [];
    foreach ($order as $k) {
        $bits[] = isset($parts[$k]) ? (string) $parts[$k] : '';
    }

    return implode('|', $bits);
}

function orange_db_router_connect_pdo(
    string $host,
    int $port,
    string $dbName,
    string $username,
    string $password,
    int $connectTimeoutSec = 5
): PDO {
    orange_db_router_validate_host_port($host, $port);
    orange_db_router_validate_db_name($dbName);
    if ($connectTimeoutSec < 1) {
        $connectTimeoutSec = 5;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    // Prefer ATTR_TIMEOUT; always save/restore default_socket_timeout (Windows/MariaDB portability).
    $options[PDO::ATTR_TIMEOUT] = $connectTimeoutSec;
    $prevSocket = ini_get('default_socket_timeout');
    try {
        ini_set('default_socket_timeout', (string) $connectTimeoutSec);
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (Throwable $e) {
        throw new OrangeDbRouterException('connection_failure', 'connection_failure');
    } finally {
        if ($prevSocket !== false && $prevSocket !== null) {
            ini_set('default_socket_timeout', (string) $prevSocket);
        }
    }

    return $pdo;
}

/**
 * @param array<string, mixed> $settings
 */
function orange_db_router_open_control(OrangeDbRouter $router, array $settings): PDO
{
    return $router->openControl($settings);
}

function orange_db_router_load_country_registry(OrangeDbRouter $router, PDO $control, string $countryCode): array
{
    return $router->loadCountryRegistryRow($control, $countryCode);
}

function orange_db_router_resolve_country_db(OrangeDbRouter $router, PDO $control, string $countryCode): array
{
    return $router->resolveCountryDb($control, $countryCode);
}

/**
 * @param callable(string,int):(?string) $secretResolver
 */
function orange_db_router_open_country_db(
    OrangeDbRouter $router,
    PDO $control,
    string $countryCode,
    callable $secretResolver
): PDO {
    return $router->openCountryDb($control, $countryCode, $secretResolver);
}

function orange_db_router_validate_country_schema(
    OrangeDbRouter $router,
    PDO $countryPdo,
    string $expectedCountryCode,
    int $requiredSchemaRevision,
    string $expectedDbName
): void {
    $router->validateCountrySchema($countryPdo, $expectedCountryCode, $requiredSchemaRevision, $expectedDbName);
}

function orange_db_router_reset_connections(OrangeDbRouter $router): void
{
    $router->resetConnections();
}
