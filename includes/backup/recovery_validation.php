<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_full.php';
require_once __DIR__ . '/backup_validate.php';

const ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION = '1.1';
const ORANGE_RECOVERY_VALIDATION_REPORT_FILE = 'recovery_validation.json';
const ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION = 124;
const ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION = '1.0';
const ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_FULL = 'full_disaster';
const ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_CRP = 'country_recovery';
const ORANGE_RECOVERY_VALIDATION_GZIP_HEAD_BYTES = 65536;
const ORANGE_RECOVERY_VALIDATION_GZIP_TAIL_BYTES = 262144;
const ORANGE_RECOVERY_VALIDATION_TIMEOUT_MANIFEST = 15;
const ORANGE_RECOVERY_VALIDATION_TIMEOUT_HEALTH = 15;
const ORANGE_RECOVERY_VALIDATION_TIMEOUT_CHECKSUMS = 300;
const ORANGE_RECOVERY_VALIDATION_TIMEOUT_GZIP_SQL = 120;
const ORANGE_RECOVERY_VALIDATION_TIMEOUT_ZIP = 60;
const ORANGE_RECOVERY_VALIDATION_TIMEOUT_CRP_SQL = 90;
const ORANGE_RECOVERY_VALIDATION_FULL_DUMP_POSTAMBLE = 'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;';

function orange_recovery_validation_log(string $message): void
{
    if (PHP_SAPI !== 'cli') {
        return;
    }
    fwrite(STDOUT, $message . PHP_EOL);
    if (function_exists('fflush')) {
        fflush(STDOUT);
    }
}

function orange_recovery_validation_deadline(int $timeoutSeconds): float
{
    return microtime(true) + max(1, $timeoutSeconds);
}

function orange_recovery_validation_timeout_error(float $deadline, string $stage): ?string
{
    if (microtime(true) <= $deadline) {
        return null;
    }

    return $stage . ': validation stage timed out';
}

function orange_recovery_validation_begin_stage(string $stage, int $timeoutSeconds): float
{
    orange_recovery_validation_log($stage . '...');
    if (PHP_SAPI === 'cli') {
        @set_time_limit(max(30, $timeoutSeconds + 10));
    }

    return orange_recovery_validation_deadline($timeoutSeconds);
}

function orange_recovery_validation_end_stage(string $stage, bool $ok, ?string $detail = null): void
{
    if ($detail !== null && $detail !== '') {
        orange_recovery_validation_log($stage . '... ' . ($ok ? 'OK' : 'FAIL') . ' (' . $detail . ')');

        return;
    }
    orange_recovery_validation_log($stage . '... ' . ($ok ? 'OK' : 'FAIL'));
}

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
 * Stream-decompress gzip SQL for full disaster packages.
 * Validates integrity using head/tail windows only — never loads multi-GB dumps into memory.
 *
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,create_table_count:int,insert_count:int,decompressed_bytes:int}
 */
function orange_recovery_validate_gzip_sql_file(string $path, string $label, ?float $deadline = null): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'errors' => [$label . ': gzip SQL file missing'], 'warnings' => [], 'create_table_count' => 0, 'insert_count' => 0, 'decompressed_bytes' => 0];
    }
    if (!function_exists('gzopen')) {
        return ['ok' => false, 'errors' => [$label . ': gzopen unavailable'], 'warnings' => [], 'create_table_count' => 0, 'insert_count' => 0, 'decompressed_bytes' => 0];
    }
    if ($deadline === null) {
        $deadline = orange_recovery_validation_deadline(ORANGE_RECOVERY_VALIDATION_TIMEOUT_GZIP_SQL);
    }

    $handle = @gzopen($path, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'errors' => [$label . ': gzip integrity check failed (cannot open)'], 'warnings' => [], 'create_table_count' => 0, 'insert_count' => 0, 'decompressed_bytes' => 0];
    }

    $head = '';
    $tail = '';
    $createCount = 0;
    $insertCount = 0;
    $totalBytes = 0;
    $errors = [];
    $warnings = [];

    while (!gzeof($handle)) {
        $timeoutError = orange_recovery_validation_timeout_error($deadline, $label . ' gzip stream');
        if ($timeoutError !== null) {
            gzclose($handle);

            return ['ok' => false, 'errors' => [$timeoutError], 'warnings' => $warnings, 'create_table_count' => $createCount, 'insert_count' => $insertCount, 'decompressed_bytes' => $totalBytes];
        }

        $chunk = gzread($handle, 65536);
        if ($chunk === false) {
            gzclose($handle);

            return ['ok' => false, 'errors' => [$label . ': gzip integrity check failed (corrupt stream)'], 'warnings' => $warnings, 'create_table_count' => $createCount, 'insert_count' => $insertCount, 'decompressed_bytes' => $totalBytes];
        }
        if ($chunk === '') {
            continue;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $chunk) === 1) {
            gzclose($handle);

            return ['ok' => false, 'errors' => [$label . ': SQL contains binary/control characters'], 'warnings' => $warnings, 'create_table_count' => $createCount, 'insert_count' => $insertCount, 'decompressed_bytes' => $totalBytes];
        }

        $chunkLen = strlen($chunk);
        $totalBytes += $chunkLen;
        $createCount += preg_match_all('/^\s*CREATE\s+TABLE\b/im', $chunk) ?: 0;
        $insertCount += preg_match_all('/^\s*INSERT\s+INTO\b/im', $chunk) ?: 0;

        if (strlen($head) < ORANGE_RECOVERY_VALIDATION_GZIP_HEAD_BYTES) {
            $head .= substr($chunk, 0, ORANGE_RECOVERY_VALIDATION_GZIP_HEAD_BYTES - strlen($head));
        }

        $tail = strlen($tail) + $chunkLen > ORANGE_RECOVERY_VALIDATION_GZIP_TAIL_BYTES
            ? substr($tail . $chunk, -ORANGE_RECOVERY_VALIDATION_GZIP_TAIL_BYTES)
            : $tail . $chunk;
    }
    gzclose($handle);

    if ($totalBytes === 0) {
        $warnings[] = $label . ': SQL payload is empty';

        return ['ok' => true, 'errors' => [], 'warnings' => $warnings, 'create_table_count' => 0, 'insert_count' => 0, 'decompressed_bytes' => 0];
    }

    if (!mb_check_encoding($head, 'UTF-8') || !mb_check_encoding($tail, 'UTF-8')) {
        $errors[] = $label . ': SQL is not valid UTF-8';
    }

    if (
        $head !== ''
        && preg_match('/CREATE\s+TABLE|INSERT\s+INTO|-- Orange Phase 1A PDO SQL export/mi', $head) !== 1
    ) {
        $warnings[] = $label . ': SQL head missing expected export markers';
    }

    $completenessError = null;
    if (str_contains($tail, ORANGE_RECOVERY_VALIDATION_FULL_DUMP_POSTAMBLE)) {
        $completenessError = orange_recovery_validate_sql_completeness($tail, $label);
    } else {
        $completenessError = orange_recovery_validate_sql_completeness($tail, $label);
        if ($completenessError === null) {
            $warnings[] = $label . ': SQL tail missing PDO export postamble marker';
        }
    }
    if ($completenessError !== null) {
        $errors[] = $label . ': ' . $completenessError;
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'warnings' => $warnings,
        'create_table_count' => $createCount,
        'insert_count' => $insertCount,
        'decompressed_bytes' => $totalBytes,
    ];
}

/**
 * @return array{ok:bool,errors:list<string>}
 */
function orange_recovery_validate_checksums(string $packageRoot, float $deadline): array
{
    $checksumFile = $packageRoot . DIRECTORY_SEPARATOR . 'checksums.sha256';
    if (!is_file($checksumFile)) {
        return ['ok' => false, 'errors' => ['Missing checksums.sha256']];
    }
    $errors = [];
    $lines = file($checksumFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return ['ok' => false, 'errors' => ['Cannot read checksums.sha256']];
    }
    foreach ($lines as $line) {
        $timeoutError = orange_recovery_validation_timeout_error($deadline, 'Checksums');
        if ($timeoutError !== null) {
            return ['ok' => false, 'errors' => [$timeoutError]];
        }
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('/^([a-f0-9]{64})\s{2}(.+)$/', $line, $m)) {
            $errors[] = 'Invalid checksum line: ' . $line;
            continue;
        }
        $expected = $m[1];
        $rel = $m[2];
        $abs = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($abs)) {
            $errors[] = 'Missing file referenced in checksums: ' . $rel;
            continue;
        }
        $actual = orange_backup_sha256_file($abs);
        if (!hash_equals($expected, $actual)) {
            $errors[] = 'Checksum mismatch: ' . $rel;
        }
    }

    return ['ok' => $errors === [], 'errors' => $errors];
}

/**
 * @param list<string> $sqlFiles
 * @return array{ok:bool,errors:list<string>,warnings:list<string>,create_table_count:int,insert_count:int}
 */
function orange_recovery_validate_sql_files(array $sqlFiles, string $labelPrefix, ?float $deadline = null): array
{
    $errors = [];
    $warnings = [];
    $createTotal = 0;
    $insertTotal = 0;
    if ($sqlFiles === []) {
        return ['ok' => false, 'errors' => [$labelPrefix . ': no SQL files found'], 'warnings' => [], 'create_table_count' => 0, 'insert_count' => 0];
    }
    if ($deadline === null) {
        $deadline = orange_recovery_validation_deadline(ORANGE_RECOVERY_VALIDATION_TIMEOUT_CRP_SQL);
    }
    foreach ($sqlFiles as $sqlFile) {
        $timeoutError = orange_recovery_validation_timeout_error($deadline, $labelPrefix . ' SQL files');
        if ($timeoutError !== null) {
            $errors[] = $timeoutError;
            break;
        }
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
function orange_recovery_validate_zip_archive(string $zipPath, string $label, ?float $deadline = null): array
{
    $errors = [];
    $warnings = [];
    if ($deadline === null) {
        $deadline = orange_recovery_validation_deadline(ORANGE_RECOVERY_VALIDATION_TIMEOUT_ZIP);
    }
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
        $timeoutError = orange_recovery_validation_timeout_error($deadline, $label . ' ZIP');
        if ($timeoutError !== null) {
            $zip->close();

            return ['ok' => false, 'errors' => [$timeoutError], 'warnings' => $warnings, 'entry_count' => $entryCount];
        }
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
    orange_recovery_validation_log('DRV validation START');

    $resolved = realpath($packagePath);
    if ($resolved === false || !is_dir($resolved)) {
        orange_recovery_validation_end_stage('Package path', false);

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

    $manifestDeadline = orange_recovery_validation_begin_stage('Manifest', ORANGE_RECOVERY_VALIDATION_TIMEOUT_MANIFEST);
    $manifestRead = orange_recovery_read_json_file($resolved . DIRECTORY_SEPARATOR . 'manifest.json', 'manifest.json');
    orange_recovery_validation_end_stage('Manifest', $manifestRead['ok']);
    orange_recovery_validation_timeout_error($manifestDeadline, 'Manifest');
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

    $healthDeadline = orange_recovery_validation_begin_stage('Health', ORANGE_RECOVERY_VALIDATION_TIMEOUT_HEALTH);
    $healthRead = orange_recovery_read_json_file($resolved . DIRECTORY_SEPARATOR . 'health.json', 'health.json');
    $healthSemantics = ['errors' => [], 'warnings' => []];
    if (!$healthRead['ok']) {
        $errors = array_merge($errors, $healthRead['errors']);
        orange_recovery_validation_end_stage('Health', false);
    } else {
        /** @var array<string, mixed> $health */
        $health = $healthRead['data'];
        $healthValid = true;
        $healthSemantics = orange_recovery_validate_health_semantics($health, $manifest, $packageType);
        $errors = array_merge($errors, $healthSemantics['errors']);
        $warnings = array_merge($warnings, $healthSemantics['warnings']);
        orange_recovery_validation_end_stage('Health', $healthSemantics['errors'] === []);
    }
    orange_recovery_validation_timeout_error($healthDeadline, 'Health');

    $checksumDeadline = orange_recovery_validation_begin_stage('Checksums', ORANGE_RECOVERY_VALIDATION_TIMEOUT_CHECKSUMS);
    $checksumVerify = orange_recovery_validate_checksums($resolved, $checksumDeadline);
    $checksumsValid = $checksumVerify['ok'];
    if (!$checksumVerify['ok']) {
        $errors = array_merge($errors, $checksumVerify['errors']);
    }
    orange_recovery_validation_end_stage('Checksums', $checksumVerify['ok']);

    if ($packageType === ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_FULL) {
        $baseDeadline = orange_recovery_validation_begin_stage('Full package verify', ORANGE_RECOVERY_VALIDATION_TIMEOUT_MANIFEST);
        $baseVerify = orange_backup_verify_full_package($resolved);
        if (!$baseVerify['ok']) {
            $errors = array_merge($errors, $baseVerify['errors']);
        }
        $warnings = array_merge($warnings, $baseVerify['warnings']);
        orange_recovery_validation_end_stage('Full package verify', $baseVerify['ok']);

        $dumpFile = (string) ($manifest['dump_file'] ?? '');
        $uploadsFile = (string) ($manifest['uploads_file'] ?? '');
        if ($dumpFile !== '') {
            $sqlDeadline = orange_recovery_validation_begin_stage('SQL dump', ORANGE_RECOVERY_VALIDATION_TIMEOUT_GZIP_SQL);
            $gzipResult = orange_recovery_validate_gzip_sql_file(
                $resolved . DIRECTORY_SEPARATOR . $dumpFile,
                'full SQL dump',
                $sqlDeadline
            );
            $sqlValid = $gzipResult['ok'];
            $errors = array_merge($errors, $gzipResult['errors']);
            $warnings = array_merge($warnings, $gzipResult['warnings']);
            orange_recovery_validation_end_stage(
                'SQL dump',
                $gzipResult['ok'],
                'decompressed_bytes=' . (string) ($gzipResult['decompressed_bytes'] ?? 0)
            );
        } else {
            $errors[] = 'manifest.dump_file missing';
            orange_recovery_validation_log('SQL dump... FAIL (missing manifest.dump_file)');
        }
        if ($uploadsFile !== '') {
            $zipDeadline = orange_recovery_validation_begin_stage('Uploads ZIP', ORANGE_RECOVERY_VALIDATION_TIMEOUT_ZIP);
            $zipResult = orange_recovery_validate_zip_archive(
                $resolved . DIRECTORY_SEPARATOR . $uploadsFile,
                'full uploads archive',
                $zipDeadline
            );
            $uploadsValid = $zipResult['ok'];
            $errors = array_merge($errors, $zipResult['errors']);
            $warnings = array_merge($warnings, $zipResult['warnings']);
            orange_recovery_validation_end_stage('Uploads ZIP', $zipResult['ok'], 'entries=' . (string) ($zipResult['entry_count'] ?? 0));
        } else {
            $errors[] = 'manifest.uploads_file missing';
            orange_recovery_validation_log('Uploads ZIP... FAIL (missing manifest.uploads_file)');
        }
        $dependencyGraphValid = true;
        $registryValid = true;
    } elseif ($packageType === ORANGE_RECOVERY_VALIDATION_PACKAGE_TYPE_CRP) {
        $baseDeadline = orange_recovery_validation_begin_stage('CRP package verify', ORANGE_RECOVERY_VALIDATION_TIMEOUT_MANIFEST);
        $baseVerify = orange_country_export_verify_package($resolved);
        if (!$baseVerify['ok']) {
            $errors = array_merge($errors, $baseVerify['errors']);
        }
        $warnings = array_merge($warnings, $baseVerify['warnings']);
        orange_recovery_validation_end_stage('CRP package verify', $baseVerify['ok']);
        orange_recovery_validation_timeout_error($baseDeadline, 'CRP package verify');

        $registryVersion = trim((string) ($manifest['registry_version'] ?? ''));
        if ($registryVersion === '') {
            $errors[] = 'manifest.registry_version missing (CRP)';
        } elseif ($registryVersion !== ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION) {
            $errors[] = 'Registry version mismatch (expected ' . ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION . ', got ' . $registryVersion . ')';
            $registryValid = false;
        } else {
            $registryValid = true;
        }
        $dependencyDeadline = orange_recovery_validation_begin_stage('Dependency graph', ORANGE_RECOVERY_VALIDATION_TIMEOUT_MANIFEST);
        $dependencyRead = orange_recovery_read_json_file($resolved . DIRECTORY_SEPARATOR . 'dependency_graph.json', 'dependency_graph.json');
        if (!$dependencyRead['ok']) {
            $errors = array_merge($errors, $dependencyRead['errors']);
            orange_recovery_validation_end_stage('Dependency graph', false);
        } else {
            $dependencyGraphValid = true;
            orange_recovery_validation_end_stage('Dependency graph', true);
        }
        orange_recovery_validation_timeout_error($dependencyDeadline, 'Dependency graph');

        $idSnapshotDeadline = orange_recovery_validation_begin_stage('ID snapshot', ORANGE_RECOVERY_VALIDATION_TIMEOUT_MANIFEST);
        $idSnapshotRead = orange_recovery_read_json_file($resolved . DIRECTORY_SEPARATOR . 'id_snapshot.json', 'id_snapshot.json');
        if (!$idSnapshotRead['ok']) {
            $errors = array_merge($errors, $idSnapshotRead['errors']);
            orange_recovery_validation_end_stage('ID snapshot', false);
        } elseif ((int) ($idSnapshotRead['data']['country_id'] ?? -1) !== (int) ($manifest['country_id'] ?? -2)) {
            $errors[] = 'id_snapshot.country_id mismatch with manifest';
            orange_recovery_validation_end_stage('ID snapshot', false);
        } else {
            orange_recovery_validation_end_stage('ID snapshot', true);
        }
        orange_recovery_validation_timeout_error($idSnapshotDeadline, 'ID snapshot');

        $sqlFiles = glob($resolved . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $sqlDeadline = orange_recovery_validation_begin_stage('CRP SQL', ORANGE_RECOVERY_VALIDATION_TIMEOUT_CRP_SQL);
        $sqlResult = orange_recovery_validate_sql_files($sqlFiles, 'CRP SQL', $sqlDeadline);
        $sqlValid = $sqlResult['ok'];
        $errors = array_merge($errors, $sqlResult['errors']);
        $warnings = array_merge($warnings, $sqlResult['warnings']);
        orange_recovery_validation_end_stage('CRP SQL', $sqlResult['ok'], 'files=' . (string) count($sqlFiles));

        $zipDeadline = orange_recovery_validation_begin_stage('CRP uploads ZIP', ORANGE_RECOVERY_VALIDATION_TIMEOUT_ZIP);
        $uploadZip = $resolved . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
        $zipResult = orange_recovery_validate_zip_archive($uploadZip, 'CRP uploads archive', $zipDeadline);
        $uploadsValid = $zipResult['ok'];
        $errors = array_merge($errors, $zipResult['errors']);
        $warnings = array_merge($warnings, $zipResult['warnings']);
        orange_recovery_validation_end_stage('CRP uploads ZIP', $zipResult['ok'], 'entries=' . (string) ($zipResult['entry_count'] ?? 0));
    }

    $errors = array_values(array_unique($errors));
    $warnings = array_values(array_unique($warnings));
    $manifestValid = $manifestRead['ok'] && !array_filter(
        $errors,
        static fn (string $e): bool => str_contains($e, 'manifest')
    );
    $healthValid = $healthRead['ok'] && $healthSemantics['errors'] === [];

    orange_recovery_validation_log('DRV validation END');

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
