<?php

declare(strict_types=1);

/**
 * Restore Step-7 private-engine SQL import-stream contract (Restore-only).
 *
 * Does NOT weaken Phase-2B.1 staging policy in restore_sql_safety.php.
 * Source package / dump bytes stay immutable; only a job-owned transient
 * stream may omit an approved canonical prelude.
 */

require_once __DIR__ . '/restore_sql_safety.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/restore_package_compat.php';
require_once __DIR__ . '/restore_sql_compat_engine.php';

const ORANGE_RESTORE_PRIVATE_IMPORT_POLICY_VERSION = '2.0.0';

const ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE = 'ONE_CANONICAL_BACKUP_USE_PRELUDE';
const ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE = 'CANONICAL_CREATE_DATABASE_AND_USE_PRELUDE';
const ORANGE_RESTORE_SQL_CLASS_MULTIPLE_OR_LATE = 'MULTIPLE_OR_LATE_DATABASE_SWITCH';
const ORANGE_RESTORE_SQL_CLASS_MISMATCHED_IDENTITY = 'MISMATCHED_DATABASE_IDENTITY';
const ORANGE_RESTORE_SQL_CLASS_CROSS_DB = 'CROSS_DATABASE_QUALIFIED_REFERENCES';
const ORANGE_RESTORE_SQL_CLASS_DB_DDL = 'DATABASE_LEVEL_DDL_OUTSIDE_APPROVED_PRELUDE';
const ORANGE_RESTORE_SQL_CLASS_FALSE_POSITIVE = 'FALSE_POSITIVE_IN_COMMENT_STRING_OR_ROUTINE';
const ORANGE_RESTORE_SQL_CLASS_MULTIPLE = 'MULTIPLE_PROVEN_SQL_STRUCTURE_CLASSES';
const ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE = 'SQL_DUMP_STRUCTURE_NOT_PROVABLE';
const ORANGE_RESTORE_SQL_CLASS_NO_DB_SWITCH = 'NO_TOP_LEVEL_DATABASE_SWITCH';

const ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED = 'STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED';
const ORANGE_RESTORE_STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES = 'STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES';
const ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH = 'STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH';
const ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE = 'STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE';
const ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_LEVEL_DDL_FORBIDDEN = 'STEP7_SQL_DUMP_DATABASE_LEVEL_DDL_FORBIDDEN';
const ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED = 'STEP7_SQL_DUMP_NORMALIZATION_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_TARGET_PREPARE_FAILED = 'STEP7_PRIVATE_TARGET_PREPARE_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_READY_IMPORT_NOT_STARTED = 'STEP7_PRIVATE_ENGINE_READY_IMPORT_NOT_STARTED';
const ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED = 'STEP7_PRIVATE_IMPORT_START_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_FAILED = 'STEP7_PRIVATE_IMPORT_FAILED';

/**
 * Map structural classification / scanner failures to Owner-safe Step-7 codes.
 */
function orange_restore_private_sql_safe_code_for_classification(string $classification): string
{
    return match ($classification) {
        ORANGE_RESTORE_SQL_CLASS_MULTIPLE_OR_LATE => ORANGE_RESTORE_STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES,
        ORANGE_RESTORE_SQL_CLASS_MISMATCHED_IDENTITY => ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH,
        ORANGE_RESTORE_SQL_CLASS_CROSS_DB => ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE,
        ORANGE_RESTORE_SQL_CLASS_DB_DDL => ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_LEVEL_DDL_FORBIDDEN,
        ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE,
        ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE => ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED,
        ORANGE_RESTORE_SQL_CLASS_FALSE_POSITIVE,
        ORANGE_RESTORE_SQL_CLASS_MULTIPLE,
        ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE => ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED,
        default => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED,
    };
}

/**
 * Normalize identifier for compare (strip backticks/quotes; lower-case).
 */
function orange_restore_private_sql_normalize_ident(string $ident): string
{
    $ident = trim($ident);
    if ($ident === '') {
        return '';
    }
    if (
        (str_starts_with($ident, '`') && str_ends_with($ident, '`'))
        || (str_starts_with($ident, '"') && str_ends_with($ident, '"'))
        || (str_starts_with($ident, "'") && str_ends_with($ident, "'"))
    ) {
        $ident = substr($ident, 1, -1);
    }

    return strtolower(str_replace('``', '`', $ident));
}

/**
 * @return list<array{kind:string,raw:string,ident:string,offset:int,is_prelude_slot:bool}>
 */
function orange_restore_private_sql_scan_top_level_directives(string $sql): array
{
    $directives = [];
    $offset = 0;
    $buffer = $sql;
    $preludeSlot = true;
    $schemaOrDataSeen = false;

    while (true) {
        $split = orange_restore_sql_runner_split_next_statement($buffer);
        if ($split === null) {
            break;
        }
        $statement = (string) ($split['statement'] ?? '');
        $buffer = (string) ($split['remainder'] ?? '');
        $stmtOffset = $offset;
        $offset += strlen($statement);
        // Account for consumed semicolon separator when present in remainder path.
        if ($buffer !== '' || $statement !== '') {
            // split helper consumes through ';' — approximate by scanning original later if needed.
        }

        $trimmed = trim($statement);
        if ($trimmed === '' || orange_restore_sql_is_comment_only($trimmed)) {
            continue;
        }

        $normalized = orange_restore_sql_strip_leading_comments($trimmed);
        if ($normalized === '') {
            continue;
        }

        $lexemes = orange_restore_sql_tokenize_executable($normalized);
        $idents = [];
        foreach ($lexemes as $lexeme) {
            if (($lexeme['type'] ?? '') === 'ident') {
                $idents[] = strtoupper((string) ($lexeme['value'] ?? ''));
            }
        }
        if ($idents === []) {
            $schemaOrDataSeen = true;
            $preludeSlot = false;
            continue;
        }

        $kind = '';
        $ident = '';
        if ($idents[0] === 'USE') {
            $kind = 'USE';
            foreach ($lexemes as $lexeme) {
                if (($lexeme['type'] ?? '') !== 'ident') {
                    continue;
                }
                $v = (string) ($lexeme['value'] ?? '');
                if (strtoupper($v) === 'USE') {
                    continue;
                }
                $ident = $v;
                break;
            }
        } elseif ($idents[0] === 'CREATE' && ($idents[1] ?? '') === 'DATABASE') {
            $kind = 'CREATE_DATABASE';
            $seenDb = false;
            foreach ($lexemes as $lexeme) {
                if (($lexeme['type'] ?? '') !== 'ident') {
                    continue;
                }
                $v = strtoupper((string) ($lexeme['value'] ?? ''));
                if (!$seenDb) {
                    if ($v === 'DATABASE') {
                        $seenDb = true;
                    }
                    continue;
                }
                if (in_array($v, ['IF', 'NOT', 'EXISTS'], true)) {
                    continue;
                }
                $ident = (string) ($lexeme['value'] ?? '');
                break;
            }
        } elseif ($idents[0] === 'DROP' && ($idents[1] ?? '') === 'DATABASE') {
            $kind = 'DROP_DATABASE';
        } elseif ($idents[0] === 'ALTER' && ($idents[1] ?? '') === 'DATABASE') {
            $kind = 'ALTER_DATABASE';
        } elseif (in_array($idents[0], ['GRANT', 'REVOKE'], true)) {
            $kind = $idents[0];
        } elseif ($idents[0] === 'SET' && ($idents[1] ?? '') === 'GLOBAL') {
            $kind = 'SET_GLOBAL';
        } elseif ($idents[0] === 'LOAD' && ($idents[1] ?? '') === 'DATA') {
            $kind = 'LOAD_DATA';
        } elseif ($idents[0] === 'SOURCE') {
            $kind = 'SOURCE';
        } elseif ($idents[0] === 'DELIMITER') {
            $kind = 'DELIMITER';
        }

        // Session/preamble SETs (NAMES, FOREIGN_KEY_CHECKS, @vars) are not schema/data.
        $isSessionSet = ($idents[0] === 'SET')
            && ($idents[1] ?? '') !== 'GLOBAL'
            && !in_array('GLOBAL', $idents, true);

        if ($kind !== '') {
            $directives[] = [
                'kind' => $kind,
                'raw' => $normalized,
                'ident' => $ident,
                'offset' => $stmtOffset,
                'is_prelude_slot' => $preludeSlot && !$schemaOrDataSeen,
            ];
            if (in_array($kind, ['USE', 'CREATE_DATABASE'], true) && $preludeSlot && !$schemaOrDataSeen) {
                // stay in prelude until a non-prelude statement appears
            } else {
                $preludeSlot = false;
                $schemaOrDataSeen = true;
            }
            continue;
        }

        if ($isSessionSet) {
            continue;
        }

        $schemaOrDataSeen = true;
        $preludeSlot = false;
    }

    return $directives;
}

/**
 * Detect foreign/system schema.object refs (delegates to authoritative SQL compat engine).
 *
 * @return list<string> normalized foreign db names (not equal to allowed)
 */
function orange_restore_private_sql_scan_cross_database_refs(string $sql, string $allowedDb): array
{
    $allowed = orange_restore_private_sql_normalize_ident($allowedDb);
    $analyze = orange_restore_sql_compat_analyze_sql($sql, $allowed);
    $found = [];
    if ((int) ($analyze['system_schema_reference_count'] ?? 0) > 0) {
        $found[] = 'system_schema';
    }
    if ((int) ($analyze['external_application_database_count'] ?? 0) > 0) {
        $found[] = 'external_database';
    }

    return $found;
}

/**
 * Classify dump structure for private-engine import (A–I + no-switch).
 *
 * @return array{
 *   ok:bool,
 *   classification:string,
 *   safe_code:string,
 *   use_count:int,
 *   create_database_count:int,
 *   prelude_use:bool,
 *   prelude_create:bool,
 *   trusted_identity_match:bool,
 *   directives:list<array<string,mixed>>,
 *   naive_use_hits:int,
 *   structural_use_count:int
 * }
 */
function orange_restore_private_sql_classify_dump(
    string $sql,
    string $trustedSourceDb
): array {
    try {
        $cert = orange_restore_sql_compat_analyze_sql($sql, $trustedSourceDb);
    } catch (Throwable) {
        return [
            'ok' => false,
            'classification' => ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE,
            'safe_code' => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED,
            'use_count' => 0,
            'create_database_count' => 0,
            'prelude_use' => false,
            'prelude_create' => false,
            'trusted_identity_match' => false,
            'directives' => [],
            'naive_use_hits' => 0,
            'structural_use_count' => 0,
            'package_classification' => ORANGE_RESTORE_SQL_PKG_SCAN_FAILED,
        ];
    }

    $internal = (string) ($cert['internal_classification'] ?? ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE);
    $pkgClass = (string) ($cert['final_compatibility_classification'] ?? ORANGE_RESTORE_SQL_PKG_SCAN_FAILED);
    $ok = !empty($cert['ok']) && !empty($cert['compatible']);
    $safe = orange_restore_sql_compat_owner_safe_code($cert);

    return [
        'ok' => $ok,
        'classification' => $internal !== '' ? $internal : ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE,
        'safe_code' => $ok ? 'ok' : $safe,
        'use_count' => (int) ($cert['structural_use_count'] ?? 0),
        'create_database_count' => (int) ($cert['canonical_database_ddl_count'] ?? 0),
        'prelude_use' => (int) ($cert['canonical_use_count'] ?? 0) > 0,
        'prelude_create' => (int) ($cert['canonical_database_ddl_count'] ?? 0) > 0,
        'trusted_identity_match' => $ok || $pkgClass !== ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_IDENTITY,
        'directives' => [],
        'naive_use_hits' => (int) ($cert['naive_use_hits'] ?? 0),
        'structural_use_count' => (int) ($cert['structural_use_count'] ?? 0),
        'package_classification' => $pkgClass,
        'certificate' => [
            'final_compatibility_classification' => $pkgClass,
            'normalization_required' => !empty($cert['normalization_required']),
            'normalization_supported' => !empty($cert['normalization_supported']),
            'real_qualified_reference_count' => (int) ($cert['real_qualified_reference_count'] ?? 0),
            'same_source_qualified_reference_count' => (int) ($cert['same_source_qualified_reference_count'] ?? 0),
            'external_application_database_count' => (int) ($cert['external_application_database_count'] ?? 0),
            'system_schema_reference_count' => (int) ($cert['system_schema_reference_count'] ?? 0),
            'parser_policy_version' => ORANGE_RESTORE_SQL_COMPAT_ENGINE_VERSION,
        ],
    ];
}

/**
 * Read gzip dump to string (bounded). Fail closed on missing/corrupt.
 *
 * @return array{ok:bool,sql:string,error:?string,sha256:string}
 */
function orange_restore_private_sql_read_gzip(string $gzipPath, int $maxBytes = 268435456): array
{
    if (!is_file($gzipPath)) {
        return ['ok' => false, 'sql' => '', 'error' => ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED, 'sha256' => ''];
    }
    if (!function_exists('gzopen')) {
        return ['ok' => false, 'sql' => '', 'error' => ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED, 'sha256' => ''];
    }
    $sha = hash_file('sha256', $gzipPath) ?: '';
    $h = @gzopen($gzipPath, 'rb');
    if ($h === false) {
        return ['ok' => false, 'sql' => '', 'error' => ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED, 'sha256' => $sha];
    }
    $sql = '';
    try {
        while (!gzeof($h)) {
            $chunk = gzread($h, 65536);
            if ($chunk === false) {
                return ['ok' => false, 'sql' => '', 'error' => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED, 'sha256' => $sha];
            }
            if ($chunk === '') {
                continue;
            }
            $sql .= $chunk;
            if (strlen($sql) > $maxBytes) {
                return ['ok' => false, 'sql' => '', 'error' => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED, 'sha256' => $sha];
            }
        }
    } finally {
        gzclose($h);
    }

    return ['ok' => true, 'sql' => $sql, 'error' => null, 'sha256' => $sha];
}

/**
 * Build normalized SQL by removing only approved prelude statements (structural).
 *
 * @return array{ok:bool,sql:string,removed_count:int,error:?string}
 */
function orange_restore_private_sql_normalize_stream(
    string $sql,
    string $classification,
    string $trustedSourceDb = '',
    string $packageClassification = ''
): array {
    // Authoritative engine path when package classification is provided.
    if ($packageClassification !== '') {
        $norm = orange_restore_sql_compat_normalize_sql($sql, $trustedSourceDb, $packageClassification);

        return [
            'ok' => !empty($norm['ok']),
            'sql' => (string) ($norm['sql'] ?? ''),
            'removed_count' => (int) ($norm['removed_count'] ?? 0),
            'remapped_count' => (int) ($norm['remapped_count'] ?? 0),
            'error' => $norm['error'] ?? null,
        ];
    }

    // Map legacy internal classes onto package classifications.
    $pkg = match ($classification) {
        ORANGE_RESTORE_SQL_CLASS_NO_DB_SWITCH,
        ORANGE_RESTORE_SQL_CLASS_FALSE_POSITIVE => ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED,
        ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE,
        ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE => ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE,
        default => '',
    };
    if ($pkg !== '') {
        $norm = orange_restore_sql_compat_normalize_sql($sql, $trustedSourceDb, $pkg);

        return [
            'ok' => !empty($norm['ok']),
            'sql' => (string) ($norm['sql'] ?? ''),
            'removed_count' => (int) ($norm['removed_count'] ?? 0),
            'remapped_count' => (int) ($norm['remapped_count'] ?? 0),
            'error' => $norm['error'] ?? null,
        ];
    }

    return [
        'ok' => false,
        'sql' => '',
        'removed_count' => 0,
        'error' => orange_restore_private_sql_safe_code_for_classification($classification),
    ];
}

/**
 * Private-engine statement validator — authoritative SQL compat engine only.
 * Phase-2B.1 staging detector is intentionally NOT used on the private import path.
 *
 * @throws RuntimeException
 */
function orange_restore_private_sql_validate_statement(
    string $sql,
    string $shadowDb,
    string $productionDb
): void {
    orange_restore_sql_compat_validate_statement_for_private_import($sql, $shadowDb, $productionDb);
}

/**
 * Package compatibility for private-engine Step-7 (separate from Phase-2B.1 staging).
 *
 * @param array<string, mixed> $manifest
 * @return array<string, mixed>
 */
function orange_restore_package_private_engine_import_compat(
    string $packagePath,
    array $manifest,
    string $shadowDb,
    string $productionDb,
    string $trustedSourceDb = ''
): array {
    unset($shadowDb);
    $backend = strtolower(trim((string) ($manifest['export_backend'] ?? '')));
    if ($backend === '') {
        return [
            'ok' => false,
            'error' => ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED,
            'export_backend' => '',
            'classification' => ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE,
        ];
    }
    if ($backend !== ORANGE_RESTORE_STAGING_SUPPORTED_EXPORT_BACKEND) {
        return [
            'ok' => false,
            'error' => ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED,
            'export_backend' => $backend,
            'classification' => ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE,
        ];
    }
    $dumpFile = trim((string) ($manifest['dump_file'] ?? ''));
    if ($dumpFile === '') {
        return [
            'ok' => false,
            'error' => ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED,
            'export_backend' => $backend,
            'classification' => ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE,
        ];
    }
    $dumpPath = $packagePath . DIRECTORY_SEPARATOR . $dumpFile;
    $read = orange_restore_private_sql_read_gzip($dumpPath);
    if (!($read['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => (string) ($read['error'] ?? ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED),
            'export_backend' => $backend,
            'classification' => ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE,
        ];
    }
    $trustedFromManifest = orange_restore_sql_compat_trusted_source_from_manifest($manifest);
    $trusted = $trustedSourceDb !== ''
        ? orange_restore_private_sql_normalize_ident($trustedSourceDb)
        : ($trustedFromManifest !== ''
            ? $trustedFromManifest
            : orange_restore_private_sql_normalize_ident($productionDb));
    $class = orange_restore_private_sql_classify_dump((string) $read['sql'], $trusted);
    $pkgClass = (string) ($class['package_classification'] ?? '');
    $ok = !empty($class['ok']) && in_array($pkgClass, [
        ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED,
        ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE,
        ORANGE_RESTORE_SQL_PKG_COMPATIBLE_SAME_SOURCE,
    ], true);

    return [
        'ok' => $ok,
        'error' => $ok ? null : (string) ($class['safe_code'] ?? ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED),
        'export_backend' => $backend,
        'classification' => (string) ($class['classification'] ?? ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE),
        'package_classification' => $pkgClass,
        'certificate' => is_array($class['certificate'] ?? null) ? $class['certificate'] : [],
        'trusted_source_identity_hash' => hash('sha256', $trusted),
        'source_dump_sha256' => (string) ($read['sha256'] ?? ''),
        'naive_use_hits' => (int) ($class['naive_use_hits'] ?? 0),
        'structural_use_count' => (int) ($class['structural_use_count'] ?? 0),
        'policy_version' => ORANGE_RESTORE_PRIVATE_IMPORT_POLICY_VERSION,
        'engine_version' => ORANGE_RESTORE_SQL_COMPAT_ENGINE_VERSION,
    ];
}

/**
 * Prepare job-owned normalized gzip stream; never mutates source dump.
 *
 * @return array<string, mixed>
 */
function orange_restore_private_sql_prepare_normalized_import(
    string $workRoot,
    string $jobId,
    string $sourceGzipPath,
    string $trustedSourceDb,
    string $classification,
    string $packageClassification = ''
): array {
    $read = orange_restore_private_sql_read_gzip($sourceGzipPath);
    if (!($read['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string) ($read['error'] ?? ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED),
        ];
    }
    $norm = orange_restore_private_sql_normalize_stream(
        (string) $read['sql'],
        $classification,
        $trustedSourceDb,
        $packageClassification
    );
    if (!($norm['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string) ($norm['error'] ?? ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED),
            'removed_count' => (int) ($norm['removed_count'] ?? 0),
        ];
    }
    $dir = orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'private_import_stream';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'code' => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED];
    }
    $outPath = $dir . DIRECTORY_SEPARATOR . 'normalized_database.sql.gz';
    $gz = gzencode((string) $norm['sql'], 1);
    if ($gz === false || file_put_contents($outPath, $gz) === false) {
        return ['ok' => false, 'code' => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED];
    }
    $normSha = hash_file('sha256', $outPath) ?: '';
    $sourceSha = (string) ($read['sha256'] ?? '');
    // Prove source path unchanged by re-hashing source.
    $sourceShaAfter = hash_file('sha256', $sourceGzipPath) ?: '';
    if ($sourceSha === '' || !hash_equals($sourceSha, $sourceShaAfter)) {
        @unlink($outPath);

        return ['ok' => false, 'code' => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'normalized_path' => $outPath,
        'source_dump_sha256' => $sourceSha,
        'normalized_stream_sha256' => $normSha,
        'removed_count' => (int) ($norm['removed_count'] ?? 0),
        'remapped_count' => (int) ($norm['remapped_count'] ?? 0),
        'policy_version' => ORANGE_RESTORE_PRIVATE_IMPORT_POLICY_VERSION,
        'engine_version' => ORANGE_RESTORE_SQL_COMPAT_ENGINE_VERSION,
        'classification' => $classification,
        'package_classification' => $packageClassification,
        'trusted_source_db_identity_hash' => hash('sha256', orange_restore_private_sql_normalize_ident($trustedSourceDb)),
    ];
}

/**
 * Delete job-owned normalized stream directory.
 */
function orange_restore_private_sql_cleanup_normalized_import(string $workRoot, string $jobId): void
{
    $dir = orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'private_import_stream';
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/**
 * Import via private-engine contract (normalized stream when required).
 *
 * @return array<string, mixed>
 */
function orange_restore_private_sql_import_gzip(
    PDO $pdo,
    string $gzipPath,
    string $shadowDb,
    string $productionDb,
    ?callable $log = null,
    string $trustedSourceDb = ''
): array {
    $trusted = $trustedSourceDb !== '' ? $trustedSourceDb : $productionDb;
    // Authoritative private import — does not call Phase-2B.1 staging validator.
    $result = orange_restore_sql_compat_import_gzip(
        $pdo,
        $gzipPath,
        $shadowDb,
        $trusted,
        $log
    );
    if (!($result['ok'] ?? false)) {
        $err = (string) ($result['error'] ?? $result['code'] ?? '');
        $mapped = orange_restore_private_sql_map_import_error($err);
        $result['error'] = $mapped;
        $result['code'] = $mapped;
    } else {
        $result['code'] = 'ok';
    }

    return $result;
}

/**
 * Map runner/validator errors to exact Step-7 SQL codes.
 */
function orange_restore_private_sql_map_import_error(string $error): string
{
    $error = trim($error);
    if ($error === '') {
        return ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_FAILED;
    }
    if (str_starts_with($error, 'STEP7_')) {
        return $error;
    }
    if (str_contains($error, 'USE database switch')
        || str_contains($error, 'rejected USE database switch')) {
        return ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED;
    }
    if (str_contains($error, 'CREATE DATABASE')
        || str_contains($error, 'DROP DATABASE')
        || str_contains($error, 'ALTER DATABASE')) {
        return ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_LEVEL_DDL_FORBIDDEN;
    }
    if (str_contains($error, 'cross-database')
        || str_contains($error, 'qualified')
        || $error === ORANGE_RESTORE_STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN
        || $error === ORANGE_RESTORE_STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN
    ) {
        return ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE;
    }
    if ($error === ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN) {
        return ORANGE_RESTORE_STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES;
    }
    if ($error === ORANGE_RESTORE_STEP7_SQL_SOURCE_IDENTITY_MISMATCH) {
        return ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH;
    }
    if ($error === ORANGE_RESTORE_STEP7_SQL_POST_PREFLIGHT_PARITY_FAILED
        || $error === ORANGE_RESTORE_STEP7_SQL_CANONICAL_PRELUDE_NORMALIZATION_FAILED
        || $error === ORANGE_RESTORE_STEP7_SQL_SAME_SOURCE_NORMALIZATION_FAILED
        || $error === ORANGE_RESTORE_STEP7_SQL_TRANSIENT_STREAM_FAILED
    ) {
        return ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED;
    }
    if (str_contains($error, 'forbidden pattern')) {
        return ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED;
    }
    if (str_contains($error, 'SQL import failed')) {
        return ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_FAILED;
    }

    return ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_FAILED;
}

/**
 * Owner Arabic for private SQL import codes.
 */
function orange_restore_private_sql_operator_reason_ar(string $code): string
{
    return match ($code) {
        ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED
            => 'تعذر قبول مقدمة ملف SQL للحزمة على مسار الاستيراد الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES
            => 'ملف SQL يحتوي أكثر من تبديل قاعدة أو تبديلاً متأخراً. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH
            => 'هوية قاعدة البيانات في مقدمة SQL لا تطابق مصدر الحزمة الموثوق. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE
            => 'ملف SQL يحتوي مراجع عبر قواعد بيانات غير مسموحة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_LEVEL_DDL_FORBIDDEN
            => 'ملف SQL يحتوي أوامر مستوى قاعدة غير مسموحة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED
            => 'تعذر تجهيز تيار الاستيراد المطبّع لملف SQL. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_TARGET_PREPARE_FAILED
            => 'تعذر تجهيز هدف قاعدة الظل داخل المحرك الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_READY_IMPORT_NOT_STARTED
            => 'محرك قاعدة الظل الخاص جاهز لكن الاستيراد لم يبدأ. راجع تشخيص الاستيراد.',
        ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_START_FAILED
            => 'تعذر بدء استيراد SQL إلى قاعدة الظل الخاصة.',
        ORANGE_RESTORE_STEP7_PRIVATE_IMPORT_FAILED
            => 'فشل استيراد SQL إلى قاعدة الظل الخاصة. لم تُمس قاعدة الإنتاج.',
        default => '',
    };
}
