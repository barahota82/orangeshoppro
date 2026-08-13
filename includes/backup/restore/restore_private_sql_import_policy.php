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

const ORANGE_RESTORE_PRIVATE_IMPORT_POLICY_VERSION = '1.0.0';

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
 * Detect fully-qualified db.table references outside strings/comments (lexemes).
 *
 * @return list<string> normalized foreign db names (not equal to allowed)
 */
function orange_restore_private_sql_scan_cross_database_refs(string $sql, string $allowedDb): array
{
    $allowed = orange_restore_private_sql_normalize_ident($allowedDb);
    $found = [];
    $buffer = $sql;
    while (true) {
        $split = orange_restore_sql_runner_split_next_statement($buffer);
        if ($split === null) {
            break;
        }
        $buffer = (string) ($split['remainder'] ?? '');
        $statement = trim((string) ($split['statement'] ?? ''));
        if ($statement === '' || orange_restore_sql_is_comment_only($statement)) {
            continue;
        }
        $normalized = orange_restore_sql_strip_leading_comments($statement);
        if ($normalized === '') {
            continue;
        }
        $lexemes = orange_restore_sql_tokenize_executable($normalized);
        $n = count($lexemes);
        for ($i = 0; $i < $n - 2; $i++) {
            $a = $lexemes[$i];
            $b = $lexemes[$i + 1];
            $c = $lexemes[$i + 2];
            if (($a['type'] ?? '') !== 'ident' || ($b['type'] ?? '') !== 'dot' || ($c['type'] ?? '') !== 'ident') {
                continue;
            }
            $db = orange_restore_private_sql_normalize_ident((string) ($a['value'] ?? ''));
            if ($db === '' || $db === $allowed) {
                continue;
            }
            $prevIdent = '';
            for ($j = $i - 1; $j >= 0; $j--) {
                if (($lexemes[$j]['type'] ?? '') === 'ident') {
                    $prevIdent = strtoupper((string) ($lexemes[$j]['value'] ?? ''));
                    break;
                }
            }
            if (in_array($prevIdent, ['USE', 'DATABASE'], true)) {
                continue;
            }
            if (!in_array($db, $found, true)) {
                $found[] = $db;
            }
        }
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
    $trusted = orange_restore_private_sql_normalize_ident($trustedSourceDb);
    $naiveUse = preg_match_all('/\bUSE\s+/i', $sql) ?: 0;
    try {
        $directives = orange_restore_private_sql_scan_top_level_directives($sql);
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
            'naive_use_hits' => (int) $naiveUse,
            'structural_use_count' => 0,
        ];
    }

    $uses = [];
    $creates = [];
    $drops = [];
    $alters = [];
    $admin = [];
    foreach ($directives as $d) {
        $kind = (string) ($d['kind'] ?? '');
        if ($kind === 'USE') {
            $uses[] = $d;
        } elseif ($kind === 'CREATE_DATABASE') {
            $creates[] = $d;
        } elseif ($kind === 'DROP_DATABASE') {
            $drops[] = $d;
        } elseif ($kind === 'ALTER_DATABASE') {
            $alters[] = $d;
        } elseif (in_array($kind, ['GRANT', 'REVOKE', 'SET_GLOBAL', 'LOAD_DATA', 'SOURCE', 'DELIMITER'], true)) {
            $admin[] = $d;
        }
    }

    $structuralUse = count($uses);
    $base = [
        'use_count' => $structuralUse,
        'create_database_count' => count($creates),
        'prelude_use' => false,
        'prelude_create' => false,
        'trusted_identity_match' => false,
        'directives' => array_map(static function (array $d): array {
            return [
                'kind' => (string) ($d['kind'] ?? ''),
                'is_prelude_slot' => !empty($d['is_prelude_slot']),
                'ident_present' => trim((string) ($d['ident'] ?? '')) !== '',
                // never expose raw SQL / DB name
            ];
        }, $directives),
        'naive_use_hits' => (int) $naiveUse,
        'structural_use_count' => $structuralUse,
    ];

    if ($admin !== [] || $drops !== [] || $alters !== []) {
        // DELIMITER alone is unsupported for private php_pdo contract.
        $class = ORANGE_RESTORE_SQL_CLASS_DB_DDL;
        if ($admin !== []) {
            foreach ($admin as $a) {
                if (($a['kind'] ?? '') === 'DELIMITER') {
                    $class = ORANGE_RESTORE_SQL_CLASS_DB_DDL;
                    break;
                }
            }
        }

        return array_merge($base, [
            'ok' => false,
            'classification' => $class,
            'safe_code' => orange_restore_private_sql_safe_code_for_classification($class),
        ]);
    }

    $cross = orange_restore_private_sql_scan_cross_database_refs($sql, $trusted !== '' ? $trustedSourceDb : '__none__');
    // When trusted is empty, skip cross-db enforcement at classify time.
    if ($trusted !== '' && $cross !== []) {
        return array_merge($base, [
            'ok' => false,
            'classification' => ORANGE_RESTORE_SQL_CLASS_CROSS_DB,
            'safe_code' => ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE,
        ]);
    }

    if ($structuralUse === 0 && $creates === []) {
        $class = ((int) $naiveUse > 0)
            ? ORANGE_RESTORE_SQL_CLASS_FALSE_POSITIVE
            : ORANGE_RESTORE_SQL_CLASS_NO_DB_SWITCH;

        return array_merge($base, [
            'ok' => true,
            'classification' => $class,
            'safe_code' => 'ok',
            'trusted_identity_match' => true,
        ]);
    }

    $lateUse = false;
    foreach ($uses as $u) {
        if (empty($u['is_prelude_slot'])) {
            $lateUse = true;
            break;
        }
    }
    $lateCreate = false;
    foreach ($creates as $c) {
        if (empty($c['is_prelude_slot'])) {
            $lateCreate = true;
            break;
        }
    }

    if ($structuralUse > 1 || $lateUse || count($creates) > 1 || $lateCreate) {
        return array_merge($base, [
            'ok' => false,
            'classification' => ORANGE_RESTORE_SQL_CLASS_MULTIPLE_OR_LATE,
            'safe_code' => ORANGE_RESTORE_STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES,
        ]);
    }

    $useIdent = orange_restore_private_sql_normalize_ident((string) ($uses[0]['ident'] ?? ''));
    $createIdent = $creates !== []
        ? orange_restore_private_sql_normalize_ident((string) ($creates[0]['ident'] ?? ''))
        : '';
    $match = $trusted !== '' && $useIdent !== '' && hash_equals($trusted, $useIdent);
    if ($creates !== [] && $trusted !== '') {
        $match = $match && $createIdent !== '' && hash_equals($trusted, $createIdent);
    }

    if ($trusted !== '' && !$match) {
        return array_merge($base, [
            'ok' => false,
            'classification' => ORANGE_RESTORE_SQL_CLASS_MISMATCHED_IDENTITY,
            'safe_code' => ORANGE_RESTORE_STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH,
            'prelude_use' => !empty($uses[0]['is_prelude_slot']),
            'prelude_create' => $creates !== [] && !empty($creates[0]['is_prelude_slot']),
            'trusted_identity_match' => false,
        ]);
    }

    if ($creates !== [] && $structuralUse === 1) {
        return array_merge($base, [
            'ok' => true,
            'classification' => ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE,
            'safe_code' => 'ok',
            'prelude_use' => true,
            'prelude_create' => true,
            'trusted_identity_match' => true,
        ]);
    }

    if ($structuralUse === 1 && $creates === []) {
        return array_merge($base, [
            'ok' => true,
            'classification' => ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE,
            'safe_code' => 'ok',
            'prelude_use' => true,
            'prelude_create' => false,
            'trusted_identity_match' => true,
        ]);
    }

    return array_merge($base, [
        'ok' => false,
        'classification' => ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE,
        'safe_code' => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED,
    ]);
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
function orange_restore_private_sql_normalize_stream(string $sql, string $classification): array
{
    if ($classification === ORANGE_RESTORE_SQL_CLASS_NO_DB_SWITCH
        || $classification === ORANGE_RESTORE_SQL_CLASS_FALSE_POSITIVE) {
        return ['ok' => true, 'sql' => $sql, 'removed_count' => 0, 'error' => null];
    }
    if ($classification !== ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE
        && $classification !== ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE) {
        return [
            'ok' => false,
            'sql' => '',
            'removed_count' => 0,
            'error' => orange_restore_private_sql_safe_code_for_classification($classification),
        ];
    }

    $out = '';
    $buffer = $sql;
    $removed = 0;
    $expectCreate = $classification === ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE;
    $expectUse = true;
    $preludeDone = false;

    while (true) {
        $split = orange_restore_sql_runner_split_next_statement($buffer);
        if ($split === null) {
            $tail = $buffer;
            if (trim($tail) !== '') {
                $out .= $tail;
            }
            break;
        }
        $statement = (string) ($split['statement'] ?? '');
        $buffer = (string) ($split['remainder'] ?? '');
        $trimmed = trim($statement);
        if ($trimmed === '' || orange_restore_sql_is_comment_only($trimmed)) {
            $out .= $statement;
            if ($buffer !== '' || true) {
                // Preserve original statement text + re-append semicolon separation via statement content.
            }
            // Re-emit with trailing semicolon for executable continuity.
            if (!str_ends_with(rtrim($statement), ';') && trim($statement) !== '') {
                $out .= ';';
            }
            continue;
        }
        $normalized = orange_restore_sql_strip_leading_comments($trimmed);
        $lexemes = orange_restore_sql_tokenize_executable($normalized);
        $idents = [];
        foreach ($lexemes as $lexeme) {
            if (($lexeme['type'] ?? '') === 'ident') {
                $idents[] = strtoupper((string) ($lexeme['value'] ?? ''));
            }
        }
        $isCreateDb = ($idents[0] ?? '') === 'CREATE' && ($idents[1] ?? '') === 'DATABASE';
        $isUse = ($idents[0] ?? '') === 'USE';
        $isSessionSet = ($idents[0] ?? '') === 'SET'
            && ($idents[1] ?? '') !== 'GLOBAL'
            && !in_array('GLOBAL', $idents, true);

        if (!$preludeDone && $expectCreate && $isCreateDb) {
            $removed++;
            $expectCreate = false;
            continue;
        }
        if (!$preludeDone && $expectUse && $isUse && !$expectCreate) {
            $removed++;
            $expectUse = false;
            $preludeDone = true;
            continue;
        }
        if (!$preludeDone && !$expectCreate && $expectUse && $isUse) {
            $removed++;
            $expectUse = false;
            $preludeDone = true;
            continue;
        }

        if ($isSessionSet) {
            $out .= rtrim($statement);
            if ($trimmed !== '' && !str_ends_with(rtrim($statement), ';')) {
                $out .= ';';
            }
            $out .= "\n";
            continue;
        }

        $preludeDone = true;
        $out .= rtrim($statement);
        if ($trimmed !== '' && !str_ends_with(rtrim($statement), ';')) {
            $out .= ';';
        }
        $out .= "\n";
    }

    $expectedRemoved = $classification === ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE ? 2 : 1;
    if ($removed !== $expectedRemoved) {
        return [
            'ok' => false,
            'sql' => '',
            'removed_count' => $removed,
            'error' => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED,
        ];
    }

    // Post-normalize: no remaining top-level USE / CREATE DATABASE.
    $post = orange_restore_private_sql_classify_dump($out, '');
    if (($post['structural_use_count'] ?? 0) > 0 || ($post['create_database_count'] ?? 0) > 0) {
        return [
            'ok' => false,
            'sql' => '',
            'removed_count' => $removed,
            'error' => ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED,
        ];
    }

    return ['ok' => true, 'sql' => $out, 'removed_count' => $removed, 'error' => null];
}

/**
 * Private-engine statement validator: reuse staging rules (still reject USE/DDL).
 *
 * @throws RuntimeException
 */
function orange_restore_private_sql_validate_statement(
    string $sql,
    string $shadowDb,
    string $productionDb
): void {
    orange_restore_sql_validate_statement_for_staging($sql, $shadowDb, $productionDb);
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
    $trusted = $trustedSourceDb !== '' ? $trustedSourceDb : $productionDb;
    $class = orange_restore_private_sql_classify_dump((string) $read['sql'], $trusted);
    $okClass = in_array((string) ($class['classification'] ?? ''), [
        ORANGE_RESTORE_SQL_CLASS_NO_DB_SWITCH,
        ORANGE_RESTORE_SQL_CLASS_FALSE_POSITIVE,
        ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE,
        ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE,
    ], true);

    return [
        'ok' => $okClass && !empty($class['ok']),
        'error' => $okClass ? null : (string) ($class['safe_code'] ?? ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED),
        'export_backend' => $backend,
        'classification' => (string) ($class['classification'] ?? ORANGE_RESTORE_SQL_CLASS_NOT_PROVABLE),
        'source_dump_sha256' => (string) ($read['sha256'] ?? ''),
        'naive_use_hits' => (int) ($class['naive_use_hits'] ?? 0),
        'structural_use_count' => (int) ($class['structural_use_count'] ?? 0),
        'policy_version' => ORANGE_RESTORE_PRIVATE_IMPORT_POLICY_VERSION,
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
    string $classification
): array {
    $read = orange_restore_private_sql_read_gzip($sourceGzipPath);
    if (!($read['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => (string) ($read['error'] ?? ORANGE_RESTORE_STEP7_SQL_DUMP_NORMALIZATION_FAILED),
        ];
    }
    $norm = orange_restore_private_sql_normalize_stream((string) $read['sql'], $classification);
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
        'policy_version' => ORANGE_RESTORE_PRIVATE_IMPORT_POLICY_VERSION,
        'classification' => $classification,
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
    ?callable $log = null
): array {
    $result = orange_restore_sql_runner_import_gzip(
        $pdo,
        $gzipPath,
        $shadowDb,
        $productionDb,
        $log
    );
    if (!($result['ok'] ?? false)) {
        $err = (string) ($result['error'] ?? '');
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
    if (str_contains($error, 'cross-database') || str_contains($error, 'qualified')) {
        return ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE;
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
