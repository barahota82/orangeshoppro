<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_full.php';
require_once __DIR__ . '/backup_validate.php';

const ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION = '1.0';
const ORANGE_RECOVERY_VALIDATION_REPORT_FILE = 'recovery_validation.json';
const ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION = 121;
const ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION = '1.0';
const ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_FULL = 'full_disaster';
const ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_CRP = 'country_recovery';

/**
 * @return array{ok:bool,data:?array<string,mixed>,errors:list<string>}
 */
function orange_recovery_read_json_file(string $path, string $label): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'data' => null, 'errors' => ['Missing ' . $label]];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['ok' => false, 'data' => null, 'errors' => ['Cannot read ' . $label]];
    }
    try {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return ['ok' => true, 'data' => $decoded, 'errors' => []];
    } catch (Throwable $e) {
        return ['ok' => false, 'data' => null, 'errors' => ['Invalid JSON in ' . $label . ': ' . $e->getMessage()]];
    }
}

function orange_recovery_sql_strip_bom(string $sqlText): string
{
    if (str_starts_with($sqlText, "\xEF\xBB\xBF")) {
        return substr($sqlText, 3);
    }

    return $sqlText;
}

function orange_recovery_sql_strip_comments(string $sqlText): string
{
    $out = preg_replace('/\/\*.*?\*\//s', '', $sqlText) ?? $sqlText;
    $out = preg_replace('/^\s*--[^\n]*$/m', '', $out) ?? $out;

    return $out;
}

/**
 * Strip CRP table-chunk metadata written by orange_country_export_table().
 *
 * Contract: header "-- Orange CRP export table=..." then zero or more INSERT chunks
 * (each ending with ";\n" from orange_backup_pdo_write_insert_chunk) then footer "-- rows=N".
 */
function orange_recovery_sql_strip_crp_chunk_metadata(string $sqlText): string
{
    $sql = rtrim(orange_recovery_sql_strip_bom($sqlText));
    if (preg_match('/-- rows=\d+\s*\z/', $sql) === 1) {
        $sql = preg_replace('/\n-- rows=\d+\s*\z/', '', $sql) ?? $sql;
    }
    $sql = preg_replace('/^-- Orange CRP export[^\n]*\n/m', '', $sql) ?? $sql;

    return $sql;
}

/**
 * Statement-aware completeness scan (respects quotes and comments).
 * Returns an error fragment or null when SQL body is complete.
 */
function orange_recovery_sql_detect_incomplete_statement(string $sqlText): ?string
{
    $sql = orange_recovery_sql_strip_bom($sqlText);
    if (trim($sql) === '') {
        return null;
    }

    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;
    $buffer = '';

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($c === "\n" || $c === "\r") {
                $inLineComment = false;
                $buffer .= $c;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($c === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble) {
            if ($c === '-' && $next === '-') {
                $inLineComment = true;
                continue;
            }
            if ($c === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if (!$inDouble && $c === "'") {
            if ($inSingle && $next === "'") {
                $buffer .= "''";
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
            $buffer .= $c;
            continue;
        }

        if (!$inSingle && $c === '"') {
            if ($inDouble && $next === '"') {
                $buffer .= '""';
                $i++;
                continue;
            }
            $inDouble = !$inDouble;
            $buffer .= $c;
            continue;
        }

        if ($inSingle && $c === '\\' && $next !== '') {
            $buffer .= $c . $next;
            $i++;
            continue;
        }

        if (!$inSingle && !$inDouble && $c === ';') {
            $buffer = '';
            continue;
        }

        $buffer .= $c;
    }

    if ($inSingle || $inDouble) {
        return 'SQL appears truncated (unclosed string literal)';
    }
    if ($inBlockComment) {
        return 'SQL appears truncated (unclosed block comment)';
    }

    $remainder = trim($buffer);
    if ($remainder === '') {
        return null;
    }

    $executableRemainder = trim(orange_recovery_sql_strip_comments($remainder));
    if ($executableRemainder === '') {
        return null;
    }

    if (preg_match('/\b(INSERT|CREATE|ALTER|UPDATE|DELETE|DROP|SET|USE)\b/i', $executableRemainder) === 1) {
        return 'SQL appears truncated (incomplete final statement)';
    }

    return null;
}

function orange_recovery_sql_basename_from_label(string $label): string
{
    $normalized = str_replace('\\', '/', $label);
    $pos = strrpos($normalized, '/');

    return $pos === false ? $normalized : substr($normalized, $pos + 1);
}

/**
 * Validate SQL completeness using export writer contracts (CRP chunks, session files, full dumps).
 */
function orange_recovery_validate_sql_completeness(string $sqlText, string $label): ?string
{
    $basename = orange_recovery_sql_basename_from_label($label);
    $sql = orange_recovery_sql_strip_bom($sqlText);
    if (trim($sql) === '') {
        return null;
    }

    $isSessionFile = preg_match('/^\d{3}_session_(preamble|postamble)\.sql$/', $basename) === 1;
    $isCrpTableChunk = !$isSessionFile && preg_match('/^\d{3}_[^\/]+\.sql$/', $basename) === 1;
    $hasCrpHeader = preg_match('/^\s*-- Orange CRP export/m', $sql) === 1;

    if ($isCrpTableChunk || $hasCrpHeader) {
        $body = orange_recovery_sql_strip_crp_chunk_metadata($sql);

        return orange_recovery_sql_detect_incomplete_statement($body);
    }

    return orange_recovery_sql_detect_incomplete_statement($sql);
}

function orange_recovery_classify_health_warning(string $warning): string
{
    $lower = strtolower($warning);
    if (
        preg_match('/missing upload file:\s*uploads\/(customers|suppliers)\//', $lower) === 1
        || (
            str_contains($lower, 'missing upload file')
            && (str_contains($lower, 'uploads/customers/') || str_contains($lower, 'uploads/suppliers/'))
        )
    ) {
        return 'informational: ' . $warning;
    }

    return 'health warning: ' . $warning;
}

/**
 * @return array{errors:list<string>,warnings:list<string>,create_table_count:int,insert_count:int}
 */
function orange_recovery_validate_sql_text(string $sqlText, string $label): array
{
    $errors = [];
    $warnings = [];
    if ($sqlText === '') {
        $warnings[] = $label . ': SQL payload is empty';

        return ['errors' => $errors, 'warnings' => $warnings, 'create_table_count' => 0, 'insert_count' => 0];
    }
    if (!mb_check_encoding($sqlText, 'UTF-8')) {
        $errors[] = $label . ': SQL is not valid UTF-8';
    }
    $createCount = preg_match_all('/^\s*CREATE\s+TABLE\b/im', $sqlText) ?: 0;
    $insertCount = preg_match_all('/^\s*INSERT\s+INTO\b/im', $sqlText) ?: 0;
    $completenessError = orange_recovery_validate_sql_completeness($sqlText, $label);
    if ($completenessError !== null) {
        $errors[] = $label . ': ' . $completenessError;
    }
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $sqlText) === 1) {
        $errors[] = $label . ': SQL contains binary/control characters';
    }

    return [
        'errors' => $errors,
        'warnings' => $warnings,
        'create_table_count' => $createCount,
        'insert_count' => $insertCount,
    ];
}

/**
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,create_table_count:int,insert_count:int}
 */
function orange_recovery_validate_gzip_sql_file(string $path, string $label): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'errors' => [$label . ': gzip SQL file missing'], 'warnings' => [], 'create_table_count' => 0, 'insert_count' => 0];
    }
    if (!function_exists('gzopen')) {
        return ['ok' => false, 'errors' => [$label . ': gzopen unavailable'], 'warnings' => [], 'create_table_count' => 0, 'insert_count' => 0];
    }
    $handle = @gzopen($path, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'errors' => [$label . ': gzip integrity check failed (cannot open)'], 'warnings' => [], 'create_table_count' => 0, 'insert_count' => 0];
    }
    $content = '';
    while (!gzeof($handle)) {
        $chunk = gzread($handle, 65536);
        if ($chunk === false) {
            gzclose($handle);

            return ['ok' => false, 'errors' => [$label . ': gzip integrity check failed (corrupt stream)'], 'warnings' => [], 'create_table_count' => 0, 'insert_count' => 0];
        }
        $content .= $chunk;
    }
    gzclose($handle);
    $analysis = orange_recovery_validate_sql_text($content, $label);

    return [
        'ok' => $analysis['errors'] === [],
        'errors' => $analysis['errors'],
        'warnings' => $analysis['warnings'],
        'create_table_count' => $analysis['create_table_count'],
        'insert_count' => $analysis['insert_count'],
    ];
}

/**
 * @param list<string> $sqlFiles
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,create_table_count:int,insert_count:int}
 */
function orange_recovery_validate_sql_files(array $sqlFiles, string $labelPrefix): array
{
    $errors = [];
    $warnings = [];
    $createTotal = 0;
    $insertTotal = 0;
    if ($sqlFiles === []) {
        return ['ok' => false, 'errors' => [$labelPrefix . ': no SQL files found'], 'warnings' => [], 'create_table_count' => 0, 'insert_count' => 0];
    }
    foreach ($sqlFiles as $sqlFile) {
        if (!is_file($sqlFile)) {
            $errors[] = $labelPrefix . ': missing SQL file ' . basename($sqlFile);
            continue;
        }
        $content = file_get_contents($sqlFile);
        if ($content === false) {
            $errors[] = $labelPrefix . ': cannot read SQL file ' . basename($sqlFile);
            continue;
        }
        $analysis = orange_recovery_validate_sql_text($content, $labelPrefix . '/' . basename($sqlFile));
        $errors = array_merge($errors, $analysis['errors']);
        $warnings = array_merge($warnings, $analysis['warnings']);
        $createTotal += $analysis['create_table_count'];
        $insertTotal += $analysis['insert_count'];
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'create_table_count' => $createTotal,
        'insert_count' => $insertTotal,
    ];
}

/**
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,entry_count:int}
 */
function orange_recovery_validate_zip_archive(string $zipPath, string $label): array
{
    $errors = [];
    $warnings = [];
    if (!is_file($zipPath)) {
        return ['ok' => false, 'errors' => [$label . ': ZIP file missing'], 'warnings' => [], 'entry_count' => 0];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'errors' => [$label . ': ZipArchive extension unavailable'], 'warnings' => [], 'entry_count' => 0];
    }
    $zip = new ZipArchive();
    $opened = $zip->open($zipPath);
    if ($opened !== true) {
        return ['ok' => false, 'errors' => [$label . ': ZIP cannot be opened (code ' . $opened . ')'], 'warnings' => [], 'entry_count' => 0];
    }
    $entryCount = $zip->numFiles;
    for ($i = 0; $i < $entryCount; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if ($name === '') {
            continue;
        }
        $normalized = str_replace('\\', '/', $name);
        if (str_starts_with($normalized, '/') || preg_match('/(^|\/)\.\.(\/|$)/', $normalized) === 1) {
            $errors[] = $label . ': ZIP traversal entry blocked: ' . $name;
        }
    }
    $zip->close();

    return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'entry_count' => $entryCount];
}

/**
 * @param array<string, mixed> $health
 * @param array<string, mixed> $manifest
 * @param string $packageType
 * @return array{errors:list<string>,warnings:list<string>}
 */
function orange_recovery_validate_health_semantics(array $health, array $manifest, string $packageType): array
{
    $errors = [];
    $warnings = [];

    $failureReasons = is_array($health['failure_reasons'] ?? null) ? $health['failure_reasons'] : [];
    if ($failureReasons !== []) {
        $errors[] = 'health.failure_reasons is not empty';
    }
    $packageStatus = (string) ($health['package_status'] ?? '');
    if ($packageStatus === 'failed') {
        $errors[] = 'health.package_status=failed';
    } elseif ($packageStatus === 'warning') {
        $warnings[] = 'health.package_status=warning';
    } elseif ($packageStatus === '' && $packageType === ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_FULL) {
        $warnings[] = 'health.package_status missing (full disaster)';
    }

    if ($packageType === ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_CRP) {
        $trialBalance = is_array($health['trial_balance'] ?? null) ? $health['trial_balance'] : [];
        $difference = abs((float) ($trialBalance['difference'] ?? 0));
        if ($difference > ORANGE_COUNTRY_EXPORT_TRIAL_BALANCE_TOLERANCE) {
            $errors[] = 'Trial balance mismatch in health report (difference=' . (string) $difference . ')';
        }
        $crossCountry = is_array($health['cross_country_validation'] ?? null) ? $health['cross_country_validation'] : [];
        $crossErrors = is_array($crossCountry['errors'] ?? null) ? $crossCountry['errors'] : [];
        if ($crossErrors !== []) {
            $errors[] = 'Cross-country validation failed in health report';
        }
        foreach ($failureReasons as $reason) {
            if (is_string($reason) && (str_contains(strtolower($reason), 'upload') || str_contains(strtolower($reason), 'critical'))) {
                $errors[] = 'Critical upload validation failed: ' . $reason;
            }
        }
        $manifestRegistry = trim((string) ($manifest['registry_version'] ?? ''));
        $healthRegistry = trim((string) ($health['registry_version'] ?? ''));
        if ($manifestRegistry !== '' && $healthRegistry !== '' && $manifestRegistry !== $healthRegistry) {
            $errors[] = 'Registry version mismatch between manifest and health';
        }
        if (
            $manifestRegistry !== ''
            && $manifestRegistry !== ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION
        ) {
            $errors[] = 'Registry version mismatch (expected ' . ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION . ', got ' . $manifestRegistry . ')';
        }
    }

    $healthWarnings = is_array($health['warnings'] ?? null) ? $health['warnings'] : [];
    foreach ($healthWarnings as $warning) {
        if (is_string($warning) && $warning !== '') {
            $warnings[] = orange_recovery_classify_health_warning($warning);
        }
    }
    $maintenanceNotes = is_array($health['maintenance_notes'] ?? null) ? $health['maintenance_notes'] : [];
    foreach ($maintenanceNotes as $note) {
        if (is_string($note) && $note !== '') {
            $warnings[] = 'informational: ' . $note;
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * @param list<string> $errors
 * @param list<string> $warnings
 * @return array{recovery_score:int,overall_result:string}
 */
function orange_recovery_compute_score(array $errors, array $warnings): array
{
    if ($errors !== []) {
        return [
            'recovery_score' => max(0, min(69, 100 - (count($errors) * 10))),
            'overall_result' => 'fail',
        ];
    }
    $hardWarnings = array_values(array_filter(
        $warnings,
        static fn (string $w): bool => !str_starts_with($w, 'informational:')
    ));
    $informational = array_values(array_filter(
        $warnings,
        static fn (string $w): bool => str_starts_with($w, 'informational:')
    ));
    if ($hardWarnings !== []) {
        return [
            'recovery_score' => max(70, min(89, 89 - max(0, count($hardWarnings) - 1))),
            'overall_result' => 'warning',
        ];
    }
    if ($informational !== []) {
        return [
            'recovery_score' => max(90, min(99, 99 - max(0, count($informational) - 1))),
            'overall_result' => 'pass',
        ];
    }

    return ['recovery_score' => 100, 'overall_result' => 'pass'];
}

/**
 * @return array<string, mixed>
 */
function orange_recovery_validate_package(string $packagePath): array
{
    $resolved = realpath($packagePath);
    if ($resolved === false || !is_dir($resolved)) {
        return orange_recovery_build_report('', 'unknown', null, null, [
            'errors' => ['Package path does not exist or is not a directory.'],
            'warnings' => [],
        ]);
    }

    $errors = [];
    $warnings = [];
    $manifest = null;
    $health = null;
    $checksumsValid = false;
    $manifestValid = false;
    $healthValid = false;
    $sqlValid = false;
    $uploadsValid = false;
    $dependencyGraphValid = false;
    $registryValid = false;

    $manifestRead = orange_recovery_read_json_file($resolved . DIRECTORY_SEPARATOR . 'manifest.json', 'manifest.json');
    if (!$manifestRead['ok']) {
        return orange_recovery_build_report($resolved, 'unknown', null, null, [
            'errors' => $manifestRead['errors'],
            'warnings' => $warnings,
        ]);
    }
    /** @var array<string, mixed> $manifest */
    $manifest = $manifestRead['data'];
    $manifestValid = true;
    $packageType = (string) ($manifest['package_type'] ?? '');
    if (!in_array($packageType, [ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_FULL, ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_CRP], true)) {
        $errors[] = 'Unsupported or missing package_type: ' . $packageType;
    }

    $schemaRevision = (int) ($manifest['schema_revision'] ?? 0);
    if ($schemaRevision <= 0) {
        $errors[] = 'manifest.schema_revision missing or invalid';
    } elseif ($schemaRevision !== ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION) {
        $errors[] = 'Schema revision mismatch (expected ' . ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION . ', got ' . $schemaRevision . ')';
    }
    $packageVersion = trim((string) ($manifest['package_version'] ?? ''));
    if ($packageVersion === '') {
        $errors[] = 'manifest.package_version missing';
    }
    $exportBackend = trim((string) ($manifest['export_backend'] ?? ($manifest['backup_engine_version'] ?? '')));
    if ($exportBackend === '') {
        $warnings[] = 'export/backup backend not recorded in manifest';
    }

    $secretViolations = orange_backup_manifest_secret_violations($manifest);
    if ($secretViolations !== []) {
        $errors = array_merge($errors, $secretViolations);
    }

    $healthRead = orange_recovery_read_json_file($resolved . DIRECTORY_SEPARATOR . 'health.json', 'health.json');
    $healthSemantics = ['errors' => [], 'warnings' => []];
    if (!$healthRead['ok']) {
        $errors = array_merge($errors, $healthRead['errors']);
    } else {
        /** @var array<string, mixed> $health */
        $health = $healthRead['data'];
        $healthValid = true;
        $healthSemantics = orange_recovery_validate_health_semantics($health, $manifest, $packageType);
        $errors = array_merge($errors, $healthSemantics['errors']);
        $warnings = array_merge($warnings, $healthSemantics['warnings']);
    }

    $checksumVerify = orange_backup_verify_checksums($resolved);
    $checksumsValid = $checksumVerify['ok'];
    if (!$checksumVerify['ok']) {
        $errors = array_merge($errors, $checksumVerify['errors']);
    }

    if ($packageType === ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_FULL) {
        $baseVerify = orange_backup_verify_full_package($resolved);
        if (!$baseVerify['ok']) {
            $errors = array_merge($errors, $baseVerify['errors']);
        }
        $warnings = array_merge($warnings, $baseVerify['warnings']);
        $dumpFile = (string) ($manifest['dump_file'] ?? '');
        $uploadsFile = (string) ($manifest['uploads_file'] ?? '');
        if ($dumpFile !== '') {
            $gzipResult = orange_recovery_validate_gzip_sql_file($resolved . DIRECTORY_SEPARATOR . $dumpFile, 'full SQL dump');
            $sqlValid = $gzipResult['ok'];
            $errors = array_merge($errors, $gzipResult['errors']);
            $warnings = array_merge($warnings, $gzipResult['warnings']);
        } else {
            $errors[] = 'manifest.dump_file missing';
        }
        if ($uploadsFile !== '') {
            $zipResult = orange_recovery_validate_zip_archive($resolved . DIRECTORY_SEPARATOR . $uploadsFile, 'full uploads archive');
            $uploadsValid = $zipResult['ok'];
            $errors = array_merge($errors, $zipResult['errors']);
            $warnings = array_merge($warnings, $zipResult['warnings']);
        } else {
            $errors[] = 'manifest.uploads_file missing';
        }
        $dependencyGraphValid = true;
        $registryValid = true;
    } elseif ($packageType === ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_CRP) {
        $baseVerify = orange_country_export_verify_package($resolved);
        if (!$baseVerify['ok']) {
            $errors = array_merge($errors, $baseVerify['errors']);
        }
        $warnings = array_merge($warnings, $baseVerify['warnings']);
        $registryVersion = trim((string) ($manifest['registry_version'] ?? ''));
        if ($registryVersion === '') {
            $errors[] = 'manifest.registry_version missing (CRP)';
        } elseif ($registryVersion !== ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION) {
            $errors[] = 'Registry version mismatch (expected ' . ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION . ', got ' . $registryVersion . ')';
            $registryValid = false;
        } else {
            $registryValid = true;
        }
        $dependencyRead = orange_recovery_read_json_file($resolved . DIRECTORY_SEPARATOR . 'dependency_graph.json', 'dependency_graph.json');
        if (!$dependencyRead['ok']) {
            $errors = array_merge($errors, $dependencyRead['errors']);
        } else {
            $dependencyGraphValid = true;
        }
        $idSnapshotRead = orange_recovery_read_json_file($resolved . DIRECTORY_SEPARATOR . 'id_snapshot.json', 'id_snapshot.json');
        if (!$idSnapshotRead['ok']) {
            $errors = array_merge($errors, $idSnapshotRead['errors']);
        } elseif ((int) ($idSnapshotRead['data']['country_id'] ?? -1) !== (int) ($manifest['country_id'] ?? -2)) {
            $errors[] = 'id_snapshot.country_id mismatch with manifest';
        }
        $sqlFiles = glob($resolved . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $sqlResult = orange_recovery_validate_sql_files($sqlFiles, 'CRP SQL');
        $sqlValid = $sqlResult['ok'];
        $errors = array_merge($errors, $sqlResult['errors']);
        $warnings = array_merge($warnings, $sqlResult['warnings']);
        $uploadZip = $resolved . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
        $zipResult = orange_recovery_validate_zip_archive($uploadZip, 'CRP uploads archive');
        $uploadsValid = $zipResult['ok'];
        $errors = array_merge($errors, $zipResult['errors']);
        $warnings = array_merge($warnings, $zipResult['warnings']);
    }

    $errors = array_values(array_unique($errors));
    $warnings = array_values(array_unique($warnings));
    $manifestValid = $manifestRead['ok'] && !array_filter(
        $errors,
        static fn (string $e): bool => str_contains($e, 'manifest')
    );
    $healthValid = $healthRead['ok'] && $healthSemantics['errors'] === [];

    return orange_recovery_build_report($resolved, $packageType, $manifest, $health, [
        'errors' => $errors,
        'warnings' => $warnings,
        'checksums_valid' => $checksumsValid,
        'manifest_valid' => $manifestValid,
        'health_valid' => $healthValid,
        'sql_valid' => $sqlValid,
        'uploads_valid' => $uploadsValid,
        'dependency_graph_valid' => $dependencyGraphValid,
        'registry_valid' => $registryValid,
    ]);
}

/**
 * @param array<string, mixed>|null $manifest
 * @param array<string, mixed>|null $health
 * @param array<string, mixed> $stageResults
 * @return array<string, mixed>
 */
function orange_recovery_build_report(
    string $packagePath,
    string $packageType,
    ?array $manifest,
    ?array $health,
    array $stageResults
): array {
    $errors = is_array($stageResults['errors'] ?? null) ? $stageResults['errors'] : [];
    $warnings = is_array($stageResults['warnings'] ?? null) ? $stageResults['warnings'] : [];
    $score = orange_recovery_compute_score($errors, $warnings);

    return [
        'validated_at' => gmdate('c'),
        'package_type' => $packageType,
        'package_path' => $packagePath,
        'schema_revision' => (int) ($manifest['schema_revision'] ?? ($health['schema_revision'] ?? 0)),
        'package_version' => (string) ($manifest['package_version'] ?? ''),
        'validation_engine_version' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
        'checksums_valid' => (bool) ($stageResults['checksums_valid'] ?? false),
        'manifest_valid' => (bool) ($stageResults['manifest_valid'] ?? false),
        'health_valid' => (bool) ($stageResults['health_valid'] ?? false),
        'sql_valid' => (bool) ($stageResults['sql_valid'] ?? false),
        'uploads_valid' => (bool) ($stageResults['uploads_valid'] ?? false),
        'dependency_graph_valid' => (bool) ($stageResults['dependency_graph_valid'] ?? false),
        'registry_valid' => (bool) ($stageResults['registry_valid'] ?? false),
        'overall_result' => $score['overall_result'],
        'recovery_score' => $score['recovery_score'],
        'warnings' => $warnings,
        'errors' => $errors,
    ];
}

/**
 * Write report beside the package directory (never inside the package).
 */
function orange_recovery_write_report_file(array $report): ?string
{
    $packagePath = (string) ($report['package_path'] ?? '');
    if ($packagePath === '') {
        return null;
    }
    $parent = dirname($packagePath);
    $base = basename($packagePath);
    $target = $parent . DIRECTORY_SEPARATOR . $base . '.' . ORANGE_RECOVERY_VALIDATION_REPORT_FILE;
    orange_backup_write_json($target, $report);

    return $target;
}

function orange_recovery_validation_exit_code(array $report): int
{
    return ((int) ($report['recovery_score'] ?? 0)) >= 70 ? 0 : 1;
}
