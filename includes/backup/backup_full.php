<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_paths.php';
require_once __DIR__ . '/backup_manifest.php';

const ORANGE_BACKUP_FULL_PACKAGE_VERSION = '1.2';
const ORANGE_BACKUP_FULL_PACKAGE_TYPE = 'full_disaster';
const ORANGE_BACKUP_HEALTH_FILE = 'health.json';
const ORANGE_BACKUP_CHECKSUMS_FILE = 'checksums.sha256';
const ORANGE_BACKUP_MANIFEST_FILE = 'manifest.json';

/** @var list<string> */
const ORANGE_BACKUP_MANIFEST_FORBIDDEN_KEYS = [
    'db_pass',
    'db_password',
    'password',
    'DB_PASS',
    'DB_USER',
    'credentials',
    'secret',
    'api_key',
    'token',
];

function orange_backup_schema_revision_live(PDO $pdo): int
{
    try {
        if (function_exists('orange_table_exists') && orange_table_exists($pdo, 'orange_schema_meta')) {
            $st = $pdo->query('SELECT version FROM orange_schema_meta WHERE id = 1 LIMIT 1');
            $version = (int) ($st ? $st->fetchColumn() : 0);
            if ($version > 0) {
                return $version;
            }
        }
    } catch (Throwable) {
    }

    return 0;
}

/**
 * @return array<string, mixed>
 */
function orange_backup_collect_safe_metadata(PDO $pdo, string $projectRoot, array $env = []): array
{
    $tableCount = 0;
    $totalRows = 0;
    $dbName = defined('DB_NAME') ? DB_NAME : '';
    $stTables = $pdo->prepare(
        'SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\''
    );
    $stTables->execute([$dbName]);
    while ($row = $stTables->fetch(PDO::FETCH_ASSOC)) {
        $tableCount++;
        $totalRows += (int) ($row['TABLE_ROWS'] ?? 0);
    }

    $schemaRevision = orange_backup_schema_revision_live($pdo);
    if ($schemaRevision <= 0 && defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
        $schemaRevision = ORANGE_CATALOG_SCHEMA_PHP_REVISION;
    }

    $projectId = trim((string) ($env['ORANGE_ENVIRONMENT_NAME'] ?? ''));
    if ($projectId === '') {
        $projectId = basename($projectRoot);
    }

    return [
        'generated_at' => gmdate('c'),
        'schema_revision' => $schemaRevision,
        'git_commit' => orange_backup_git_commit_hash($projectRoot),
        'database_name' => $dbName,
        'database_host' => defined('DB_HOST') ? DB_HOST : '',
        'table_count' => $tableCount,
        'approx_total_rows' => $totalRows,
        'project_identifier' => $projectId,
    ];
}

/**
 * @param array<string, mixed> $manifest
 * @return list<string>
 */
function orange_backup_manifest_secret_violations(array $manifest): array
{
    $violations = [];
    foreach ($manifest as $key => $value) {
        $keyLower = strtolower((string) $key);
        foreach (ORANGE_BACKUP_MANIFEST_FORBIDDEN_KEYS as $forbidden) {
            if ($keyLower === strtolower($forbidden)) {
                $violations[] = 'Forbidden manifest key: ' . $key;
            }
        }
        if (is_array($value)) {
            foreach (orange_backup_manifest_secret_violations($value) as $nested) {
                $violations[] = $nested;
            }
        }
    }

    return $violations;
}

/**
 * @param array<string, mixed> $options
 * @return array{manifest:array<string,mixed>,health:array<string,mixed>,package_status:string}
 */
function orange_backup_full_finalize_workspace(array $options): array
{
    $workspace = rtrim((string) ($options['workspace'] ?? ''), '\\/');
    $backupRoot = (string) ($options['backup_root'] ?? '');
    $dumpFile = (string) ($options['dump_file'] ?? '');
    $uploadsFile = (string) ($options['uploads_file'] ?? '');
    /** @var array<string, mixed> $metadata */
    $metadata = $options['metadata'] ?? [];
    $metadataOk = (bool) ($options['metadata_ok'] ?? false);
    $exportBackend = trim((string) ($options['export_backend'] ?? ''));
    $backupEngineVersion = trim((string) ($options['backup_engine_version'] ?? ''));
    /** @var list<string> $exporterWarnings */
    $exporterWarnings = is_array($options['exporter_warnings'] ?? null) ? $options['exporter_warnings'] : [];
    /** @var list<string> $exporterMaintenanceNotes */
    $exporterMaintenanceNotes = is_array($options['exporter_maintenance_notes'] ?? null) ? $options['exporter_maintenance_notes'] : [];

    $warnings = [];
    $maintenanceNotes = array_values(array_unique($exporterMaintenanceNotes));
    $failureReasons = [];
    $generatedAt = gmdate('c');

    if ($workspace === '' || !is_dir($workspace)) {
        throw new RuntimeException('Invalid backup workspace.');
    }
    if ($dumpFile === '' || $uploadsFile === '') {
        throw new RuntimeException('Dump and uploads filenames are required.');
    }

    $dumpPath = $workspace . DIRECTORY_SEPARATOR . $dumpFile;
    $uploadsPath = $workspace . DIRECTORY_SEPARATOR . $uploadsFile;

    $backupRootSafetyPassed = false;
    try {
        if ($backupRoot !== '') {
            $env = is_array($options['env'] ?? null) ? $options['env'] : [];
            orange_backup_resolve_root($env, $backupRoot);
            $backupRootSafetyPassed = true;
        }
    } catch (Throwable $e) {
        $failureReasons[] = 'BackupRoot safety failed: ' . $e->getMessage();
    }

    $dumpCreated = is_file($dumpPath);
    $uploadsCreated = is_file($uploadsPath);
    $dumpNonZero = $dumpCreated && filesize($dumpPath) > 0;
    $uploadsReadable = $uploadsCreated && is_readable($uploadsPath);

    if (!$dumpCreated) {
        $failureReasons[] = 'Database dump file missing.';
    }
    if (!$uploadsCreated) {
        $failureReasons[] = 'Uploads archive missing.';
    }
    if ($dumpCreated && !$dumpNonZero) {
        $failureReasons[] = 'Database dump has zero size.';
    }
    if ($uploadsCreated && !$uploadsReadable) {
        $failureReasons[] = 'Uploads archive is not readable.';
    }

    if (!$metadataOk) {
        $warnings[] = 'Metadata collection unavailable; schema/table counts may be incomplete.';
    }
    if ($exporterWarnings !== []) {
        $warnings = array_values(array_unique(array_merge($warnings, $exporterWarnings)));
    }

    $dumpSha256 = '';
    $uploadsSha256 = '';
    $dumpChecksumVerified = false;
    $uploadsChecksumVerified = false;

    if ($dumpNonZero) {
        try {
            $dumpSha256 = orange_backup_sha256_file($dumpPath);
            $dumpChecksumVerified = true;
        } catch (Throwable $e) {
            $failureReasons[] = 'Dump checksum failed: ' . $e->getMessage();
        }
    }

    if ($uploadsReadable) {
        try {
            $uploadsSha256 = orange_backup_sha256_file($uploadsPath);
            $uploadsChecksumVerified = true;
        } catch (Throwable $e) {
            $failureReasons[] = 'Uploads checksum failed: ' . $e->getMessage();
        }
    }

    $schemaRevision = (int) ($metadata['schema_revision'] ?? 0);
    if ($schemaRevision <= 0) {
        $warnings[] = 'Schema revision unavailable from metadata.';
    }

    $manifest = [
        'package_type' => ORANGE_BACKUP_FULL_PACKAGE_TYPE,
        'package_version' => ORANGE_BACKUP_FULL_PACKAGE_VERSION,
        'generated_at' => $generatedAt,
        'schema_revision' => $schemaRevision > 0 ? $schemaRevision : null,
        'git_commit' => $metadata['git_commit'] ?? null,
        'source_database' => $metadata['database_name'] ?? null,
        'database_host' => $metadata['database_host'] ?? null,
        'project_identifier' => $metadata['project_identifier'] ?? null,
        'dump_file' => $dumpFile,
        'uploads_file' => $uploadsFile,
        'dump_sha256' => $dumpSha256 !== '' ? $dumpSha256 : null,
        'uploads_sha256' => $uploadsSha256 !== '' ? $uploadsSha256 : null,
        'dump_size_bytes' => $dumpNonZero ? filesize($dumpPath) : 0,
        'uploads_size_bytes' => $uploadsReadable ? filesize($uploadsPath) : 0,
        'table_count' => $metadata['table_count'] ?? null,
        'approx_total_rows' => $metadata['approx_total_rows'] ?? null,
        'backup_status' => 'pending',
        'health_report_file' => ORANGE_BACKUP_HEALTH_FILE,
        'checksums_file' => ORANGE_BACKUP_CHECKSUMS_FILE,
    ];
    if ($exportBackend !== '') {
        $manifest['export_backend'] = $exportBackend;
    }
    if ($backupEngineVersion !== '') {
        $manifest['backup_engine_version'] = $backupEngineVersion;
    }

    $secretViolations = orange_backup_manifest_secret_violations($manifest);
    if ($secretViolations !== []) {
        $failureReasons = array_merge($failureReasons, $secretViolations);
    }

    orange_backup_write_json($workspace . DIRECTORY_SEPARATOR . ORANGE_BACKUP_MANIFEST_FILE, $manifest);

    $inventory = orange_backup_collect_package_files($workspace);
    $inventory = array_values(array_filter(
        $inventory,
        static fn (string $rel): bool => $rel !== ORANGE_BACKUP_CHECKSUMS_FILE
    ));

    $health = [
        'generated_at' => $generatedAt,
        'schema_revision' => $schemaRevision > 0 ? $schemaRevision : null,
        'metadata_collection_status' => $metadataOk ? 'ok' : 'unavailable',
        'database_dump_created' => $dumpCreated,
        'uploads_archive_created' => $uploadsCreated,
        'dump_non_zero_size' => $dumpNonZero,
        'uploads_archive_readable' => $uploadsReadable,
        'dump_checksum_verified' => $dumpChecksumVerified,
        'uploads_checksum_verified' => $uploadsChecksumVerified,
        'backup_root_safety_passed' => $backupRootSafetyPassed,
        'package_file_inventory' => $inventory,
        'warnings' => $warnings,
        'failure_reasons' => $failureReasons,
        'package_status' => 'healthy',
    ];
    if ($exportBackend !== '') {
        $health['export_backend'] = $exportBackend;
    }
    if ($backupEngineVersion !== '') {
        $health['backup_engine_version'] = $backupEngineVersion;
    }
    if ($maintenanceNotes !== []) {
        $health['maintenance_notes'] = $maintenanceNotes;
    }

    orange_backup_write_json($workspace . DIRECTORY_SEPARATOR . ORANGE_BACKUP_HEALTH_FILE, $health);

    $checksumTargets = array_values(array_unique(array_merge([$dumpFile, $uploadsFile], [
        ORANGE_BACKUP_MANIFEST_FILE,
        ORANGE_BACKUP_HEALTH_FILE,
    ])));
    orange_backup_write_checksums($workspace, $checksumTargets);

    $checksumVerify = orange_backup_verify_checksums($workspace);
    if (!$checksumVerify['ok']) {
        $failureReasons = array_merge($failureReasons, $checksumVerify['errors']);
        $health['failure_reasons'] = array_values(array_unique($failureReasons));
    }

    $packageStatus = 'healthy';
    if ($failureReasons !== []) {
        $packageStatus = 'failed';
    }

    $health['package_status'] = $packageStatus;
    $health['failure_reasons'] = array_values(array_unique($failureReasons));
    $health['warnings'] = $warnings;
    if ($maintenanceNotes !== []) {
        $health['maintenance_notes'] = $maintenanceNotes;
    }
    $health['package_file_inventory'] = orange_backup_collect_package_files($workspace);
    $health['package_file_inventory'] = array_values(array_filter(
        $health['package_file_inventory'],
        static fn (string $rel): bool => $rel !== ORANGE_BACKUP_CHECKSUMS_FILE
    ));

    orange_backup_write_json($workspace . DIRECTORY_SEPARATOR . ORANGE_BACKUP_HEALTH_FILE, $health);

    orange_backup_write_checksums($workspace, array_values(array_unique(array_merge([$dumpFile, $uploadsFile], [
        ORANGE_BACKUP_MANIFEST_FILE,
        ORANGE_BACKUP_HEALTH_FILE,
    ]))));

    $finalChecksumVerify = orange_backup_verify_checksums($workspace);
    if (!$finalChecksumVerify['ok']) {
        $packageStatus = 'failed';
        $health['package_status'] = 'failed';
        $health['failure_reasons'] = array_values(array_unique(array_merge(
            $health['failure_reasons'],
            $finalChecksumVerify['errors']
        )));
        orange_backup_write_json($workspace . DIRECTORY_SEPARATOR . ORANGE_BACKUP_HEALTH_FILE, $health);
    }

    $backupStatus = $packageStatus === 'healthy' ? 'success' : 'failed';
    $manifest['backup_status'] = $backupStatus;
    if ($maintenanceNotes !== []) {
        $manifest['maintenance_notes'] = $maintenanceNotes;
    }
    orange_backup_write_json($workspace . DIRECTORY_SEPARATOR . ORANGE_BACKUP_MANIFEST_FILE, $manifest);

    orange_backup_write_checksums($workspace, array_values(array_unique(array_merge([$dumpFile, $uploadsFile], [
        ORANGE_BACKUP_MANIFEST_FILE,
        ORANGE_BACKUP_HEALTH_FILE,
    ]))));

    return [
        'manifest' => $manifest,
        'health' => $health,
        'package_status' => $packageStatus,
    ];
}

/**
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,manifest:array<string,mixed>|null,health:array<string,mixed>|null}
 */
function orange_backup_verify_full_package(string $packagePath): array
{
    $errors = [];
    $warnings = [];

    $resolved = realpath($packagePath);
    if ($resolved === false || !is_dir($resolved)) {
        return [
            'ok' => false,
            'errors' => ['Package path does not exist or is not a directory.'],
            'warnings' => [],
            'manifest' => null,
            'health' => null,
        ];
    }

    if (str_contains(str_replace('\\', '/', $resolved), '/..')) {
        return [
            'ok' => false,
            'errors' => ['Package path contains traversal segments.'],
            'warnings' => [],
            'manifest' => null,
            'health' => null,
        ];
    }

    $manifestPath = $resolved . DIRECTORY_SEPARATOR . ORANGE_BACKUP_MANIFEST_FILE;
    if (!is_file($manifestPath)) {
        return [
            'ok' => false,
            'errors' => ['Missing manifest.json'],
            'warnings' => [],
            'manifest' => null,
            'health' => null,
        ];
    }

    $manifestRaw = file_get_contents($manifestPath);
    if ($manifestRaw === false) {
        return [
            'ok' => false,
            'errors' => ['Cannot read manifest.json'],
            'warnings' => [],
            'manifest' => null,
            'health' => null,
        ];
    }

    try {
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'errors' => ['Invalid manifest.json: ' . $e->getMessage()],
            'warnings' => [],
            'manifest' => null,
            'health' => null,
        ];
    }

    if (($manifest['package_type'] ?? '') !== ORANGE_BACKUP_FULL_PACKAGE_TYPE) {
        $errors[] = 'Unexpected package_type (expected ' . ORANGE_BACKUP_FULL_PACKAGE_TYPE . ').';
    }

    $secretViolations = orange_backup_manifest_secret_violations($manifest);
    if ($secretViolations !== []) {
        $errors = array_merge($errors, $secretViolations);
    }

    if (!isset($manifest['schema_revision']) || $manifest['schema_revision'] === null || $manifest['schema_revision'] === '') {
        $errors[] = 'manifest.schema_revision is missing.';
    }

    $requiredManifestKeys = [
        'package_version',
        'generated_at',
        'dump_file',
        'uploads_file',
        'dump_sha256',
        'uploads_sha256',
        'dump_size_bytes',
        'uploads_size_bytes',
        'backup_status',
        'health_report_file',
        'checksums_file',
    ];
    foreach ($requiredManifestKeys as $key) {
        if (!array_key_exists($key, $manifest)) {
            $errors[] = 'Missing manifest field: ' . $key;
        }
    }

    $dumpFile = (string) ($manifest['dump_file'] ?? '');
    $uploadsFile = (string) ($manifest['uploads_file'] ?? '');
    if ($dumpFile === '' || !is_file($resolved . DIRECTORY_SEPARATOR . $dumpFile)) {
        $errors[] = 'Dump file missing: ' . $dumpFile;
    }
    if ($uploadsFile === '' || !is_file($resolved . DIRECTORY_SEPARATOR . $uploadsFile)) {
        $errors[] = 'Uploads archive missing: ' . $uploadsFile;
    }

    $healthPath = $resolved . DIRECTORY_SEPARATOR . ORANGE_BACKUP_HEALTH_FILE;
    $health = null;
    if (!is_file($healthPath)) {
        $errors[] = 'Missing health.json';
    } else {
        $healthRaw = file_get_contents($healthPath);
        if ($healthRaw === false) {
            $errors[] = 'Cannot read health.json';
        } else {
            try {
                /** @var array<string, mixed> $health */
                $health = json_decode($healthRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $errors[] = 'Invalid health.json: ' . $e->getMessage();
            }
        }
    }

    if (!is_file($resolved . DIRECTORY_SEPARATOR . ORANGE_BACKUP_CHECKSUMS_FILE)) {
        $errors[] = 'Missing checksums.sha256';
    } else {
        $checksumVerify = orange_backup_verify_checksums($resolved);
        if (!$checksumVerify['ok']) {
            $errors = array_merge($errors, $checksumVerify['errors']);
        }
    }

    if (is_array($health)) {
        $status = (string) ($health['package_status'] ?? '');
        if ($status === 'failed') {
            $errors[] = 'health.json reports package_status=failed';
        } elseif ($status === 'warning') {
            $warnings[] = 'health.json reports package_status=warning';
        } elseif ($status !== 'healthy') {
            $errors[] = 'health.json package_status missing or invalid.';
        }
    }

    if (($manifest['backup_status'] ?? '') === 'failed') {
        $errors[] = 'manifest.backup_status is failed';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'manifest' => $manifest,
        'health' => $health,
    ];
}
