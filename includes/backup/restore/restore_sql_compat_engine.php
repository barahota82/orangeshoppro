<?php

declare(strict_types=1);

/**
 * Restore-only authoritative SQL compatibility engine (Step 7).
 *
 * Single tokenizer / token-event model for:
 * package scan, directive scan, qualified-ref scan, prelude classification,
 * same-source normalization, transient stream generation, import validation.
 *
 * Does not modify Backup Center Production dump generation.
 * Phase-2B.1 staging policy in restore_sql_safety.php remains separate.
 */

require_once __DIR__ . '/restore_sql_safety.php';
require_once __DIR__ . '/restore_sql_runner.php';

const ORANGE_RESTORE_SQL_COMPAT_ENGINE_VERSION = '2.0.0';
const ORANGE_RESTORE_SQL_COMPAT_ENGINE_COUNT_MARKER = 1;

/** Package certificate classifications (§9). */
const ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED = 'SQL_PACKAGE_COMPATIBLE_UNCHANGED';
const ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE = 'SQL_PACKAGE_COMPATIBLE_CANONICAL_PRELUDE_NORMALIZATION';
const ORANGE_RESTORE_SQL_PKG_COMPATIBLE_SAME_SOURCE = 'SQL_PACKAGE_COMPATIBLE_SAME_SOURCE_REFERENCE_NORMALIZATION';
const ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_EXTERNAL = 'SQL_PACKAGE_INCOMPATIBLE_EXTERNAL_DATABASE';
const ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_SYSTEM = 'SQL_PACKAGE_INCOMPATIBLE_SYSTEM_SCHEMA';
const ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_MULTIPLE = 'SQL_PACKAGE_INCOMPATIBLE_MULTIPLE_DATABASES';
const ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_IDENTITY = 'SQL_PACKAGE_INCOMPATIBLE_SOURCE_IDENTITY';
const ORANGE_RESTORE_SQL_PKG_AMBIGUOUS = 'SQL_PACKAGE_AMBIGUOUS_STRUCTURE';
const ORANGE_RESTORE_SQL_PKG_SCAN_FAILED = 'SQL_PACKAGE_SCAN_FAILED';

/** Exact failure codes (§13). */
const ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED = 'STEP7_SQL_PACKAGE_SCAN_FAILED';
const ORANGE_RESTORE_STEP7_SQL_STRUCTURE_AMBIGUOUS = 'STEP7_SQL_STRUCTURE_AMBIGUOUS';
const ORANGE_RESTORE_STEP7_SQL_SOURCE_IDENTITY_MISMATCH = 'STEP7_SQL_SOURCE_IDENTITY_MISMATCH';
const ORANGE_RESTORE_STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN = 'STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN';
const ORANGE_RESTORE_STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN = 'STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN';
const ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN = 'STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN';
const ORANGE_RESTORE_STEP7_SQL_CANONICAL_PRELUDE_NORMALIZATION_FAILED = 'STEP7_SQL_CANONICAL_PRELUDE_NORMALIZATION_FAILED';
const ORANGE_RESTORE_STEP7_SQL_SAME_SOURCE_NORMALIZATION_FAILED = 'STEP7_SQL_SAME_SOURCE_NORMALIZATION_FAILED';
const ORANGE_RESTORE_STEP7_SQL_TRANSIENT_STREAM_FAILED = 'STEP7_SQL_TRANSIENT_STREAM_FAILED';
const ORANGE_RESTORE_STEP7_SQL_POST_PREFLIGHT_PARITY_FAILED = 'STEP7_SQL_POST_PREFLIGHT_PARITY_FAILED';

/** @var list<string> */
const ORANGE_RESTORE_SQL_SYSTEM_SCHEMAS = [
    'mysql',
    'information_schema',
    'performance_schema',
    'sys',
];

/**
 * Normalize identifier for compare (strip quotes/backticks; lower-case).
 */
function orange_restore_sql_compat_normalize_ident(string $ident): string
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
 * Trusted source DB identity from verified package contract only.
 *
 * @param array<string, mixed> $manifest
 */
function orange_restore_sql_compat_trusted_source_from_manifest(array $manifest): string
{
    foreach (['source_database', 'database_name'] as $key) {
        $v = trim((string) ($manifest[$key] ?? ''));
        if ($v !== '') {
            return orange_restore_sql_compat_normalize_ident($v);
        }
    }

    return '';
}

/**
 * Emit token events from SQL using the single Restore tokenizer state machine.
 *
 * Versioned executable MySQL comments (bang-version form) are treated as executable SQL.
 * Optimizer hints / plain block comments remain non-executable.
 *
 * @return list<array{type:string,value?:string,offset:int}>
 */
function orange_restore_sql_compat_tokenize_events(string $sql): array
{
    // Expand executable MySQL version comments to plain SQL; leave other blocks intact for strip.
    $expanded = preg_replace_callback(
        '/\/\*!(\d{5})?(.*?)\*\//s',
        static function (array $m): string {
            return ' ' . (string) ($m[2] ?? '') . ' ';
        },
        $sql
    );
    if (!is_string($expanded)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED);
    }

    $base = orange_restore_sql_tokenize_executable($expanded);
    $events = [];
    $offset = 0;
    foreach ($base as $lex) {
        $events[] = [
            'type' => (string) ($lex['type'] ?? 'other'),
            'value' => (string) ($lex['value'] ?? ''),
            'offset' => $offset++,
        ];
    }

    return $events;
}

/**
 * @return list<string>
 */
function orange_restore_sql_compat_system_schemas(): array
{
    return ORANGE_RESTORE_SQL_SYSTEM_SCHEMAS;
}

function orange_restore_sql_compat_is_system_schema(string $db): bool
{
    return in_array(orange_restore_sql_compat_normalize_ident($db), ORANGE_RESTORE_SQL_SYSTEM_SCHEMAS, true);
}

function orange_restore_sql_compat_is_numeric_pair(string $left, string $right): bool
{
    return preg_match('/^[0-9]+$/', $left) === 1 && preg_match('/^[0-9]+$/', $right) === 1;
}

/**
 * True when left.right is DEFINER user@host shape (ident @ ident), not db.object.
 * Detected via surrounding tokens when scanning statements that include DEFINER=.
 *
 * @param list<array{type:string,value?:string}> $lexemes
 */
function orange_restore_sql_compat_is_definer_user_host(array $lexemes, int $i): bool
{
    // DEFINER = user @ host — tokenizer yields ident DEFINER, other =, ident user, other @, ident host
    for ($j = $i - 1; $j >= 0; $j--) {
        $t = (string) ($lexemes[$j]['type'] ?? '');
        $v = strtoupper((string) ($lexemes[$j]['value'] ?? ''));
        if ($t === 'ident' && $v === 'DEFINER') {
            return true;
        }
        if ($t === 'ident' && !in_array($v, ['CURRENT_USER'], true)) {
            // keep scanning back a short window
            if ($i - $j > 6) {
                break;
            }
        }
    }

    return false;
}

/**
 * Scan one statement's lexemes for real schema.object references.
 *
 * @param list<array{type:string,value?:string}> $lexemes
 * @return list<array{db:string,object:string,kind:string}>
 */
function orange_restore_sql_compat_scan_qualified_in_lexemes(array $lexemes): array
{
    $out = [];
    $n = count($lexemes);
    for ($i = 0; $i < $n - 2; $i++) {
        if (($lexemes[$i]['type'] ?? '') !== 'ident'
            || ($lexemes[$i + 1]['type'] ?? '') !== 'dot'
            || ($lexemes[$i + 2]['type'] ?? '') !== 'ident'
        ) {
            continue;
        }
        $left = (string) ($lexemes[$i]['value'] ?? '');
        $right = (string) ($lexemes[$i + 2]['value'] ?? '');
        if (orange_restore_sql_compat_is_numeric_pair($left, $right)) {
            continue;
        }
        if (orange_restore_sql_compat_is_definer_user_host($lexemes, $i)) {
            continue;
        }
        if (!orange_restore_sql_is_schema_qualified_object_context($lexemes, $i)) {
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
        $db = orange_restore_sql_compat_normalize_ident($left);
        $obj = orange_restore_sql_compat_normalize_ident($right);
        if ($db === '' || $obj === '') {
            continue;
        }
        $kind = 'object';
        if (orange_restore_sql_compat_is_system_schema($db)) {
            $kind = 'system';
        }
        $out[] = ['db' => $db, 'object' => $obj, 'kind' => $kind];
    }

    return $out;
}

/**
 * @return list<array{kind:string,ident:string,is_prelude_slot:bool}>
 */
function orange_restore_sql_compat_scan_directives(string $sql): array
{
    $directives = [];
    $buffer = $sql;
    $preludeSlot = true;
    $schemaOrDataSeen = false;

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
        $lexemes = orange_restore_sql_compat_tokenize_events($normalized);
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

        $isSessionSet = ($idents[0] === 'SET')
            && ($idents[1] ?? '') !== 'GLOBAL'
            && !in_array('GLOBAL', $idents, true);

        if ($kind !== '') {
            $directives[] = [
                'kind' => $kind,
                'ident' => orange_restore_sql_compat_normalize_ident($ident),
                'is_prelude_slot' => $preludeSlot && !$schemaOrDataSeen,
            ];
            if (!(in_array($kind, ['USE', 'CREATE_DATABASE'], true) && $preludeSlot && !$schemaOrDataSeen)) {
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
 * Full package SQL scan → redacted certificate (§9).
 *
 * @param array<string, mixed> $manifest
 * @return array<string, mixed>
 */
function orange_restore_sql_compat_scan_package(
    string $gzipPath,
    array $manifest,
    string $trustedSourceDb,
    string $packageFingerprintVerified = 'unknown',
    string $dumpChecksumVerified = 'unknown'
): array {
    $policy = ORANGE_RESTORE_SQL_COMPAT_ENGINE_VERSION;
    $base = [
        'parser_policy_version' => $policy,
        'package_dump_contract_version' => (string) ($manifest['package_version'] ?? $manifest['export_backend'] ?? ''),
        'package_identity_matches_job' => true,
        'package_fingerprint_verified' => $packageFingerprintVerified,
        'sql_dump_checksum_verified' => $dumpChecksumVerified,
        'statement_count' => 0,
        'canonical_use_count' => 0,
        'canonical_database_ddl_count' => 0,
        'real_qualified_reference_count' => 0,
        'same_source_qualified_reference_count' => 0,
        'external_application_database_count' => 0,
        'system_schema_reference_count' => 0,
        'distinct_database_identity_count' => 0,
        'false_positive_comment_string_count' => 0,
        'ambiguous_token_count' => 0,
        'stored_object_reference_count_by_type' => [
            'view' => 0,
            'trigger' => 0,
            'procedure' => 0,
            'function' => 0,
            'event' => 0,
        ],
        'normalization_required' => false,
        'normalization_supported' => false,
        'original_dump_unchanged' => true,
        'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_SCAN_FAILED,
        'exact_not_ready_reason' => ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED,
        'safe_code' => ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED,
        'ok' => false,
        'compatible' => false,
        'engine_version' => $policy,
        'trusted_source_identity_hash' => $trustedSourceDb !== ''
            ? hash('sha256', orange_restore_sql_compat_normalize_ident($trustedSourceDb))
            : '',
        'source_dump_sha256' => '',
        'internal_classification' => '',
    ];

    if (!is_file($gzipPath) || !function_exists('gzopen')) {
        return $base;
    }

    $sha = hash_file('sha256', $gzipPath) ?: '';
    $base['source_dump_sha256'] = $sha;
    $h = @gzopen($gzipPath, 'rb');
    if ($h === false) {
        return $base;
    }
    $sql = '';
    try {
        while (!gzeof($h)) {
            $chunk = gzread($h, 65536);
            if ($chunk === false) {
                return $base;
            }
            $sql .= $chunk;
            if (strlen($sql) > 268435456) {
                $base['exact_not_ready_reason'] = ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED;
                $base['final_compatibility_classification'] = ORANGE_RESTORE_SQL_PKG_SCAN_FAILED;

                return $base;
            }
        }
    } finally {
        gzclose($h);
    }

    try {
        $result = orange_restore_sql_compat_analyze_sql($sql, $trustedSourceDb);
    } catch (Throwable) {
        $base['final_compatibility_classification'] = ORANGE_RESTORE_SQL_PKG_SCAN_FAILED;
        $base['exact_not_ready_reason'] = ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED;
        $base['safe_code'] = ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED;

        return $base;
    }

    return array_merge($base, $result, [
        'source_dump_sha256' => $sha,
        'parser_policy_version' => $policy,
        'engine_version' => $policy,
        'original_dump_unchanged' => true,
        'package_fingerprint_verified' => $packageFingerprintVerified,
        'sql_dump_checksum_verified' => $dumpChecksumVerified,
        'trusted_source_identity_hash' => $trustedSourceDb !== ''
            ? hash('sha256', orange_restore_sql_compat_normalize_ident($trustedSourceDb))
            : '',
    ]);
}

/**
 * Analyze SQL string (already decompressed) into certificate fields + internal class.
 *
 * @return array<string, mixed>
 */
function orange_restore_sql_compat_analyze_sql(string $sql, string $trustedSourceDb): array
{
    $trusted = orange_restore_sql_compat_normalize_ident($trustedSourceDb);
    $naiveUse = preg_match_all('/\bUSE\s+/i', $sql) ?: 0;
    $directives = orange_restore_sql_compat_scan_directives($sql);

    $uses = [];
    $creates = [];
    $admin = [];
    foreach ($directives as $d) {
        $kind = (string) ($d['kind'] ?? '');
        if ($kind === 'USE') {
            $uses[] = $d;
        } elseif ($kind === 'CREATE_DATABASE') {
            $creates[] = $d;
        } elseif (in_array($kind, ['DROP_DATABASE', 'ALTER_DATABASE', 'GRANT', 'REVOKE', 'SET_GLOBAL', 'LOAD_DATA', 'SOURCE', 'DELIMITER'], true)) {
            $admin[] = $d;
        }
    }

    $statementCount = 0;
    $qualified = [];
    $stored = ['view' => 0, 'trigger' => 0, 'procedure' => 0, 'function' => 0, 'event' => 0];
    $falsePositive = 0;
    $buffer = $sql;
    while (true) {
        $split = orange_restore_sql_runner_split_next_statement($buffer);
        if ($split === null) {
            break;
        }
        $buffer = (string) ($split['remainder'] ?? '');
        $statement = trim((string) ($split['statement'] ?? ''));
        if ($statement === '' || orange_restore_sql_is_comment_only($statement)) {
            if (preg_match('/\bUSE\s+/i', $statement) === 1) {
                $falsePositive++;
            }
            continue;
        }
        $normalized = orange_restore_sql_strip_leading_comments($statement);
        if ($normalized === '') {
            continue;
        }
        $statementCount++;
        $upper = strtoupper($normalized);
        if (str_contains($upper, 'CREATE VIEW') || str_contains($upper, 'CREATE OR REPLACE VIEW')) {
            $stored['view']++;
        }
        if (str_contains($upper, 'CREATE TRIGGER')) {
            $stored['trigger']++;
        }
        if (str_contains($upper, 'CREATE PROCEDURE') || str_contains($upper, 'CREATE DEFINER')) {
            if (str_contains($upper, 'PROCEDURE')) {
                $stored['procedure']++;
            }
            if (str_contains($upper, 'FUNCTION')) {
                $stored['function']++;
            }
        }
        if (str_contains($upper, 'CREATE EVENT')) {
            $stored['event']++;
        }
        try {
            $lex = orange_restore_sql_compat_tokenize_events($normalized);
        } catch (Throwable) {
            return [
                'ok' => false,
                'compatible' => false,
                'statement_count' => $statementCount,
                'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_AMBIGUOUS,
                'exact_not_ready_reason' => ORANGE_RESTORE_STEP7_SQL_STRUCTURE_AMBIGUOUS,
                'safe_code' => ORANGE_RESTORE_STEP7_SQL_STRUCTURE_AMBIGUOUS,
                'internal_classification' => 'SQL_DUMP_STRUCTURE_NOT_PROVABLE',
                'ambiguous_token_count' => 1,
            ];
        }
        foreach (orange_restore_sql_compat_scan_qualified_in_lexemes($lex) as $q) {
            $qualified[] = $q;
        }
    }

    $sameSource = 0;
    $external = 0;
    $system = 0;
    $distinct = [];
    foreach ($qualified as $q) {
        $db = (string) $q['db'];
        $distinct[$db] = true;
        if (($q['kind'] ?? '') === 'system' || orange_restore_sql_compat_is_system_schema($db)) {
            $system++;
            continue;
        }
        if ($trusted !== '' && $db === $trusted) {
            $sameSource++;
            continue;
        }
        $external++;
    }

    $canonicalUse = 0;
    $canonicalDdl = 0;
    foreach ($uses as $u) {
        if (!empty($u['is_prelude_slot'])) {
            $canonicalUse++;
        }
    }
    foreach ($creates as $c) {
        if (!empty($c['is_prelude_slot'])) {
            $canonicalDdl++;
        }
    }

    $cert = [
        'statement_count' => $statementCount,
        'canonical_use_count' => $canonicalUse,
        'canonical_database_ddl_count' => $canonicalDdl,
        'real_qualified_reference_count' => count($qualified),
        'same_source_qualified_reference_count' => $sameSource,
        'external_application_database_count' => $external,
        'system_schema_reference_count' => $system,
        'distinct_database_identity_count' => count($distinct),
        'false_positive_comment_string_count' => $falsePositive + max(0, (int) $naiveUse - count($uses)),
        'ambiguous_token_count' => 0,
        'stored_object_reference_count_by_type' => $stored,
        'naive_use_hits' => (int) $naiveUse,
        'structural_use_count' => count($uses),
    ];

    if ($admin !== []) {
        return array_merge($cert, [
            'ok' => false,
            'compatible' => false,
            'normalization_required' => false,
            'normalization_supported' => false,
            'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_MULTIPLE,
            'exact_not_ready_reason' => ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN,
            'safe_code' => ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN,
            'internal_classification' => 'DATABASE_LEVEL_DDL_OUTSIDE_APPROVED_PRELUDE',
        ]);
    }

    if ($system > 0) {
        return array_merge($cert, [
            'ok' => false,
            'compatible' => false,
            'normalization_required' => false,
            'normalization_supported' => false,
            'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_SYSTEM,
            'exact_not_ready_reason' => ORANGE_RESTORE_STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN,
            'safe_code' => ORANGE_RESTORE_STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN,
            // Legacy Owner dialog code for live parity.
            'legacy_safe_code' => 'STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE',
            'internal_classification' => 'CROSS_DATABASE_QUALIFIED_REFERENCES',
        ]);
    }

    if ($external > 0) {
        return array_merge($cert, [
            'ok' => false,
            'compatible' => false,
            'normalization_required' => false,
            'normalization_supported' => false,
            'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_EXTERNAL,
            'exact_not_ready_reason' => ORANGE_RESTORE_STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN,
            'safe_code' => ORANGE_RESTORE_STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN,
            'legacy_safe_code' => 'STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE',
            'internal_classification' => 'CROSS_DATABASE_QUALIFIED_REFERENCES',
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
    if (count($uses) > 1 || $lateUse || count($creates) > 1 || $lateCreate) {
        return array_merge($cert, [
            'ok' => false,
            'compatible' => false,
            'normalization_required' => false,
            'normalization_supported' => false,
            'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_MULTIPLE,
            'exact_not_ready_reason' => ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN,
            'safe_code' => ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN,
            'legacy_safe_code' => 'STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES',
            'internal_classification' => 'MULTIPLE_OR_LATE_DATABASE_SWITCH',
        ]);
    }

    if (count($uses) === 1) {
        $useIdent = (string) ($uses[0]['ident'] ?? '');
        $createIdent = $creates !== [] ? (string) ($creates[0]['ident'] ?? '') : '';
        $match = $trusted !== '' && $useIdent !== '' && hash_equals($trusted, $useIdent);
        if ($creates !== [] && $trusted !== '') {
            $match = $match && $createIdent !== '' && hash_equals($trusted, $createIdent);
        }
        if ($trusted !== '' && !$match) {
            return array_merge($cert, [
                'ok' => false,
                'compatible' => false,
                'normalization_required' => false,
                'normalization_supported' => false,
                'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_IDENTITY,
                'exact_not_ready_reason' => ORANGE_RESTORE_STEP7_SQL_SOURCE_IDENTITY_MISMATCH,
                'safe_code' => ORANGE_RESTORE_STEP7_SQL_SOURCE_IDENTITY_MISMATCH,
                'legacy_safe_code' => 'STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH',
                'internal_classification' => 'MISMATCHED_DATABASE_IDENTITY',
            ]);
        }
    }

    // Compatible classes
    if ($sameSource > 0 && count($uses) === 0 && $creates === []) {
        return array_merge($cert, [
            'ok' => true,
            'compatible' => true,
            'normalization_required' => true,
            'normalization_supported' => true,
            'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_COMPATIBLE_SAME_SOURCE,
            'exact_not_ready_reason' => '',
            'safe_code' => 'ok',
            'internal_classification' => 'NO_TOP_LEVEL_DATABASE_SWITCH',
        ]);
    }

    if ($sameSource > 0 && (count($uses) === 1 || $creates !== [])) {
        return array_merge($cert, [
            'ok' => true,
            'compatible' => true,
            'normalization_required' => true,
            'normalization_supported' => true,
            'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_COMPATIBLE_SAME_SOURCE,
            'exact_not_ready_reason' => '',
            'safe_code' => 'ok',
            'internal_classification' => $creates !== []
                ? 'CANONICAL_CREATE_DATABASE_AND_USE_PRELUDE'
                : 'ONE_CANONICAL_BACKUP_USE_PRELUDE',
        ]);
    }

    if (count($uses) === 1 && $creates !== []) {
        return array_merge($cert, [
            'ok' => true,
            'compatible' => true,
            'normalization_required' => true,
            'normalization_supported' => true,
            'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE,
            'exact_not_ready_reason' => '',
            'safe_code' => 'ok',
            'internal_classification' => 'CANONICAL_CREATE_DATABASE_AND_USE_PRELUDE',
        ]);
    }

    if (count($uses) === 1 && $creates === []) {
        return array_merge($cert, [
            'ok' => true,
            'compatible' => true,
            'normalization_required' => true,
            'normalization_supported' => true,
            'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE,
            'exact_not_ready_reason' => '',
            'safe_code' => 'ok',
            'internal_classification' => 'ONE_CANONICAL_BACKUP_USE_PRELUDE',
        ]);
    }

    if (count($uses) === 0 && $creates === []) {
        $internal = ((int) $naiveUse > 0)
            ? 'FALSE_POSITIVE_IN_COMMENT_STRING_OR_ROUTINE'
            : 'NO_TOP_LEVEL_DATABASE_SWITCH';

        return array_merge($cert, [
            'ok' => true,
            'compatible' => true,
            'normalization_required' => false,
            'normalization_supported' => true,
            'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED,
            'exact_not_ready_reason' => '',
            'safe_code' => 'ok',
            'internal_classification' => $internal,
        ]);
    }

    return array_merge($cert, [
        'ok' => false,
        'compatible' => false,
        'normalization_required' => false,
        'normalization_supported' => false,
        'final_compatibility_classification' => ORANGE_RESTORE_SQL_PKG_AMBIGUOUS,
        'exact_not_ready_reason' => ORANGE_RESTORE_STEP7_SQL_STRUCTURE_AMBIGUOUS,
        'safe_code' => ORANGE_RESTORE_STEP7_SQL_STRUCTURE_AMBIGUOUS,
        'internal_classification' => 'SQL_DUMP_STRUCTURE_NOT_PROVABLE',
    ]);
}

/**
 * Map certificate / analyze result to Owner-facing safe code (prefer §13; keep legacy CROSS_DB for UI).
 *
 * @param array<string, mixed> $cert
 */
function orange_restore_sql_compat_owner_safe_code(array $cert): string
{
    if (!empty($cert['ok']) && !empty($cert['compatible'])) {
        return 'ok';
    }
    $code = (string) ($cert['safe_code'] ?? '');
    if ($code !== '' && $code !== 'ok') {
        // Surface legacy CROSS_DB Arabic for external/system until Owner UI maps §13 codes.
        if (in_array($code, [
            ORANGE_RESTORE_STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN,
            ORANGE_RESTORE_STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN,
        ], true)) {
            return 'STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE';
        }
        if ($code === ORANGE_RESTORE_STEP7_SQL_SOURCE_IDENTITY_MISMATCH) {
            return 'STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH';
        }
        if ($code === ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN) {
            return 'STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES';
        }

        return $code;
    }

    return ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED;
}

/**
 * Normalize SQL using the same token events as scan.
 * - Removes approved canonical USE / CREATE DATABASE prelude statements.
 * - Unqualifies same-source db.object → object (token-level, not global regex).
 *
 * @return array{ok:bool,sql:string,removed_count:int,remapped_count:int,error:?string}
 */
function orange_restore_sql_compat_normalize_sql(
    string $sql,
    string $trustedSourceDb,
    string $pkgClassification
): array {
    $trusted = orange_restore_sql_compat_normalize_ident($trustedSourceDb);

    if ($pkgClassification === ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED) {
        return ['ok' => true, 'sql' => $sql, 'removed_count' => 0, 'remapped_count' => 0, 'error' => null];
    }
    if (!in_array($pkgClassification, [
        ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE,
        ORANGE_RESTORE_SQL_PKG_COMPATIBLE_SAME_SOURCE,
    ], true)) {
        return [
            'ok' => false,
            'sql' => '',
            'removed_count' => 0,
            'remapped_count' => 0,
            'error' => ORANGE_RESTORE_STEP7_SQL_CANONICAL_PRELUDE_NORMALIZATION_FAILED,
        ];
    }

    $out = '';
    $buffer = $sql;
    $removed = 0;
    $remapped = 0;
    $analyze = orange_restore_sql_compat_analyze_sql($sql, $trustedSourceDb);
    $internal = (string) ($analyze['internal_classification'] ?? '');
    $expectCreate = $internal === 'CANONICAL_CREATE_DATABASE_AND_USE_PRELUDE';
    $expectUse = in_array($internal, [
        'ONE_CANONICAL_BACKUP_USE_PRELUDE',
        'CANONICAL_CREATE_DATABASE_AND_USE_PRELUDE',
    ], true);
    $needSameSource = ((int) ($analyze['same_source_qualified_reference_count'] ?? 0) > 0)
        || $pkgClassification === ORANGE_RESTORE_SQL_PKG_COMPATIBLE_SAME_SOURCE;

    while (true) {
        $split = orange_restore_sql_runner_split_next_statement($buffer);
        if ($split === null) {
            $tail = $buffer;
            if (trim($tail) !== '') {
                $out .= $tail;
            }
            break;
        }
        $buffer = (string) ($split['remainder'] ?? '');
        $statement = (string) ($split['statement'] ?? '');
        $trimmed = trim($statement);
        if ($trimmed === '' || orange_restore_sql_is_comment_only($trimmed)) {
            $out .= $statement;
            if ($buffer !== '' || str_ends_with($sql, ';')) {
                // keep separators approximate — append semicolon if original split consumed one
            }
            continue;
        }
        $normalized = orange_restore_sql_strip_leading_comments($trimmed);
        $lexemes = orange_restore_sql_compat_tokenize_events($normalized);
        $idents = [];
        foreach ($lexemes as $lexeme) {
            if (($lexeme['type'] ?? '') === 'ident') {
                $idents[] = strtoupper((string) ($lexeme['value'] ?? ''));
            }
        }

        $isCreateDb = ($idents[0] ?? '') === 'CREATE' && ($idents[1] ?? '') === 'DATABASE';
        $isUse = ($idents[0] ?? '') === 'USE';

        if ($expectCreate && $isCreateDb) {
            $removed++;
            $expectCreate = false;
            continue;
        }
        if ($expectUse && $isUse) {
            $removed++;
            $expectUse = false;
            continue;
        }

        // Same-source unqualify: rebuild statement from lexemes when remaps needed.
        if ($needSameSource && $trusted !== '') {
            $rebuilt = orange_restore_sql_compat_unqualify_same_source_statement($normalized, $trusted, $remapped);
            $out .= $rebuilt . ";\n";
            continue;
        }

        $out .= $normalized . ";\n";
    }

    if ($expectCreate || $expectUse) {
        return [
            'ok' => false,
            'sql' => '',
            'removed_count' => $removed,
            'remapped_count' => $remapped,
            'error' => ORANGE_RESTORE_STEP7_SQL_CANONICAL_PRELUDE_NORMALIZATION_FAILED,
        ];
    }

    // Post-normalize parity: re-scan must stay compatible / no USE left.
    try {
        $post = orange_restore_sql_compat_analyze_sql($out, $trusted);
    } catch (Throwable) {
        return [
            'ok' => false,
            'sql' => '',
            'removed_count' => $removed,
            'remapped_count' => $remapped,
            'error' => ORANGE_RESTORE_STEP7_SQL_POST_PREFLIGHT_PARITY_FAILED,
        ];
    }
    if ((int) ($post['structural_use_count'] ?? 0) > 0
        || (int) ($post['external_application_database_count'] ?? 0) > 0
        || (int) ($post['system_schema_reference_count'] ?? 0) > 0
        || (int) ($post['same_source_qualified_reference_count'] ?? 0) > 0
    ) {
        return [
            'ok' => false,
            'sql' => '',
            'removed_count' => $removed,
            'remapped_count' => $remapped,
            'error' => ORANGE_RESTORE_STEP7_SQL_POST_PREFLIGHT_PARITY_FAILED,
        ];
    }

    return ['ok' => true, 'sql' => $out, 'removed_count' => $removed, 'remapped_count' => $remapped, 'error' => null];
}

/**
 * Rebuild a statement unqualifying trusted.db object refs only in object contexts.
 */
function orange_restore_sql_compat_unqualify_same_source_statement(
    string $statement,
    string $trusted,
    int &$remapped
): string {
    // Structural rewrite via regex on backtick-qualified forms only when scan already approved.
    // Avoid global replace: operate on tokenized reconstruction for ident.dot.ident in object context.
    $lexemes = orange_restore_sql_tokenize_executable($statement);
    $n = count($lexemes);
    $skip = [];
    for ($i = 0; $i < $n - 2; $i++) {
        if (($lexemes[$i]['type'] ?? '') !== 'ident'
            || ($lexemes[$i + 1]['type'] ?? '') !== 'dot'
            || ($lexemes[$i + 2]['type'] ?? '') !== 'ident'
        ) {
            continue;
        }
        $left = (string) ($lexemes[$i]['value'] ?? '');
        $right = (string) ($lexemes[$i + 2]['value'] ?? '');
        if (orange_restore_sql_compat_is_numeric_pair($left, $right)) {
            continue;
        }
        if (!orange_restore_sql_is_schema_qualified_object_context($lexemes, $i)) {
            continue;
        }
        if (orange_restore_sql_compat_normalize_ident($left) !== $trusted) {
            continue;
        }
        $skip[$i] = true; // drop schema ident
        $skip[$i + 1] = true; // drop dot
        $remapped++;
    }

    // Best-effort rebuild: prefer original statement when no remaps.
    if ($remapped === 0 || $skip === []) {
        return $statement;
    }

    // Token rebuild cannot perfectly restore whitespace; apply targeted replacements for `db`.`obj` / db.obj.
    $patterns = [
        '/`' . preg_quote($trusted, '/') . '`\s*\.\s*`([^`]+)`/i',
        '/(?<![A-Za-z0-9_`])' . preg_quote($trusted, '/') . '\s*\.\s*`([^`]+)`/i',
        '/`' . preg_quote($trusted, '/') . '`\s*\.\s*([A-Za-z0-9_$]+)/i',
        '/(?<![A-Za-z0-9_`])' . preg_quote($trusted, '/') . '\s*\.\s*([A-Za-z0-9_$]+)/i',
    ];
    $rebuilt = $statement;
    foreach ($patterns as $p) {
        $rebuilt = preg_replace($p, '`$1`', $rebuilt) ?? $rebuilt;
    }

    return $rebuilt;
}

/**
 * Private-engine statement validation — same rules as package scan (no Phase-2B.1 staging gate).
 *
 * @throws RuntimeException
 */
function orange_restore_sql_compat_validate_statement_for_private_import(
    string $sql,
    string $shadowDb,
    string $trustedSourceDb
): void {
    unset($shadowDb);
    $trimmed = trim($sql);
    if ($trimmed === '' || orange_restore_sql_is_comment_only($trimmed)) {
        return;
    }
    $normalized = orange_restore_sql_strip_leading_comments($trimmed);
    if ($normalized === '') {
        return;
    }
    if (preg_match('/^DELIMITER\b/i', orange_restore_sql_collapse_whitespace($normalized)) === 1) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN);
    }
    $lexemes = orange_restore_sql_compat_tokenize_events($normalized);
    $idents = [];
    foreach ($lexemes as $lexeme) {
        if (($lexeme['type'] ?? '') === 'ident') {
            $idents[] = strtoupper((string) ($lexeme['value'] ?? ''));
        }
    }
    if ($idents !== []) {
        if ($idents[0] === 'USE'
            || ($idents[0] === 'CREATE' && ($idents[1] ?? '') === 'DATABASE')
            || ($idents[0] === 'DROP' && ($idents[1] ?? '') === 'DATABASE')
            || ($idents[0] === 'ALTER' && ($idents[1] ?? '') === 'DATABASE')
            || in_array($idents[0], ['GRANT', 'REVOKE', 'SOURCE'], true)
            || (($idents[0] === 'SET') && ($idents[1] ?? '') === 'GLOBAL')
            || (($idents[0] === 'LOAD') && ($idents[1] ?? '') === 'DATA')
        ) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN);
        }
    }
    $trusted = orange_restore_sql_compat_normalize_ident($trustedSourceDb);
    foreach (orange_restore_sql_compat_scan_qualified_in_lexemes($lexemes) as $q) {
        $db = (string) $q['db'];
        if (orange_restore_sql_compat_is_system_schema($db)) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN);
        }
        if ($trusted !== '' && $db === $trusted) {
            // Should have been unqualified by normalizer; fail closed on parity breach.
            throw new RuntimeException(ORANGE_RESTORE_STEP7_SQL_POST_PREFLIGHT_PARITY_FAILED);
        }
        throw new RuntimeException(ORANGE_RESTORE_STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN);
    }
}

/**
 * Import gzip using the authoritative private validator (not Phase-2B.1 staging detector).
 *
 * @return array{ok:bool,statements_executed:int,bytes_read:int,error:?string,code?:string}
 */
function orange_restore_sql_compat_import_gzip(
    PDO $pdo,
    string $gzipPath,
    string $shadowDb,
    string $trustedSourceDb,
    ?callable $log = null
): array {
    $log = $log ?? static function (string $m): void {
    };
    if (!is_file($gzipPath)) {
        return [
            'ok' => false,
            'statements_executed' => 0,
            'bytes_read' => 0,
            'error' => 'STEP7_PRIVATE_IMPORT_START_FAILED',
            'code' => 'STEP7_PRIVATE_IMPORT_START_FAILED',
        ];
    }
    if (!function_exists('gzopen')) {
        return [
            'ok' => false,
            'statements_executed' => 0,
            'bytes_read' => 0,
            'error' => 'STEP7_PRIVATE_IMPORT_START_FAILED',
            'code' => 'STEP7_PRIVATE_IMPORT_START_FAILED',
        ];
    }
    $handle = @gzopen($gzipPath, 'rb');
    if ($handle === false) {
        return [
            'ok' => false,
            'statements_executed' => 0,
            'bytes_read' => 0,
            'error' => 'STEP7_PRIVATE_IMPORT_START_FAILED',
            'code' => 'STEP7_PRIVATE_IMPORT_START_FAILED',
        ];
    }

    $buffer = '';
    $bytesRead = 0;
    $statementsExecuted = 0;
    $lastProgressAt = 0;
    try {
        orange_restore_sql_assert_session_database($pdo, $shadowDb);
        while (!gzeof($handle)) {
            $chunk = gzread($handle, 65536);
            if ($chunk === false) {
                throw new RuntimeException('STEP7_PRIVATE_IMPORT_FAILED');
            }
            if ($chunk === '') {
                continue;
            }
            $bytesRead += strlen($chunk);
            $buffer .= $chunk;
            while (true) {
                $split = orange_restore_sql_runner_split_next_statement($buffer);
                if ($split === null) {
                    break;
                }
                $buffer = $split['remainder'];
                $statement = trim($split['statement']);
                if ($statement === '' || orange_restore_sql_is_comment_only($statement)) {
                    continue;
                }
                orange_restore_sql_assert_session_database($pdo, $shadowDb);
                orange_restore_sql_compat_validate_statement_for_private_import(
                    $statement,
                    $shadowDb,
                    $trustedSourceDb
                );
                try {
                    $pdo->exec($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException('STEP7_PRIVATE_IMPORT_FAILED');
                }
                orange_restore_sql_assert_session_database($pdo, $shadowDb);
                $statementsExecuted++;
                if ($statementsExecuted - $lastProgressAt >= 500) {
                    $log('SQL import... progress statements=' . (string) $statementsExecuted);
                    $lastProgressAt = $statementsExecuted;
                }
            }
        }
        $tail = trim($buffer);
        if ($tail !== '' && !orange_restore_sql_is_comment_only($tail)) {
            throw new RuntimeException('STEP7_PRIVATE_IMPORT_FAILED');
        }
    } catch (Throwable $e) {
        gzclose($handle);
        $msg = $e->getMessage();

        return [
            'ok' => false,
            'statements_executed' => $statementsExecuted,
            'bytes_read' => $bytesRead,
            'error' => $msg,
            'code' => str_starts_with($msg, 'STEP7_') ? $msg : 'STEP7_PRIVATE_IMPORT_FAILED',
        ];
    }
    gzclose($handle);
    orange_restore_sql_assert_session_database($pdo, $shadowDb);
    $log('SQL import... OK (statements=' . (string) $statementsExecuted . ')');

    return [
        'ok' => true,
        'statements_executed' => $statementsExecuted,
        'bytes_read' => $bytesRead,
        'error' => null,
        'code' => 'ok',
    ];
}

/**
 * Owner Arabic for §13 + legacy codes.
 */
function orange_restore_sql_compat_operator_reason_ar(string $code): string
{
    return match ($code) {
        ORANGE_RESTORE_STEP7_SQL_PACKAGE_SCAN_FAILED
            => 'تعذر فحص توافق ملف SQL للحزمة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_STRUCTURE_AMBIGUOUS
            => 'بنية ملف SQL غير حاسمة ولا يمكن اعتمادها. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_SOURCE_IDENTITY_MISMATCH,
        'STEP7_SQL_DUMP_DATABASE_IDENTITY_MISMATCH'
            => 'هوية قاعدة البيانات في مقدمة SQL لا تطابق مصدر الحزمة الموثوق. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_EXTERNAL_DATABASE_FORBIDDEN,
        ORANGE_RESTORE_STEP7_SQL_SYSTEM_SCHEMA_FORBIDDEN,
        'STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE'
            => 'ملف SQL يحتوي مراجع عبر قواعد بيانات غير مسموحة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_MULTIPLE_DATABASES_FORBIDDEN,
        'STEP7_SQL_DUMP_MULTIPLE_DATABASE_SWITCHES'
            => 'ملف SQL يحتوي أكثر من تبديل قاعدة أو تبديلاً متأخراً. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_CANONICAL_PRELUDE_NORMALIZATION_FAILED,
        ORANGE_RESTORE_STEP7_SQL_SAME_SOURCE_NORMALIZATION_FAILED,
        ORANGE_RESTORE_STEP7_SQL_TRANSIENT_STREAM_FAILED,
        'STEP7_SQL_DUMP_NORMALIZATION_FAILED'
            => 'تعذر تجهيز تيار الاستيراد المطبّع لملف SQL. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_SQL_POST_PREFLIGHT_PARITY_FAILED
            => 'تعارض بين فحص التوافق وتجهيز الاستيراد. لم يبدأ التنفيذ.',
        default => '',
    };
}
