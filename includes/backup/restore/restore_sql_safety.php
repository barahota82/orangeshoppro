<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_staging_target.php';

/**
 * Assert the MySQL session is connected to the configured staging database.
 */
function orange_restore_sql_assert_session_database(PDO $pdo, string $stagingDb): void
{
    orange_restore_staging_assert_safe_target($pdo, $stagingDb);
}

/**
 * Validate a SQL statement before staging import execution. Fail closed — never rewrite.
 *
 * @throws RuntimeException
 */
function orange_restore_sql_validate_statement_for_staging(
    string $sql,
    string $stagingDb,
    string $productionDb
): void {
    $trimmed = trim($sql);
    if ($trimmed === '' || orange_restore_sql_is_comment_only($trimmed)) {
        return;
    }

    $normalized = orange_restore_sql_strip_leading_comments($trimmed);
    if ($normalized === '') {
        return;
    }

    if (preg_match('/^DELIMITER\b/i', orange_restore_sql_collapse_whitespace($normalized)) === 1) {
        throw new RuntimeException(
            'Staging SQL import rejected DELIMITER directive (unsupported in Phase 2B.1).'
        );
    }

    $lexemes = orange_restore_sql_tokenize_executable($normalized);
    orange_restore_sql_reject_forbidden_leading_keywords($lexemes);
    orange_restore_sql_reject_cross_database_references_lexemes($lexemes, $stagingDb, $productionDb);
}

/**
 * Collapse any run of ASCII whitespace to a single space (for keyword probes only).
 */
function orange_restore_sql_collapse_whitespace(string $sql): string
{
    $collapsed = preg_replace('/\s+/u', ' ', $sql);

    return is_string($collapsed) ? trim($collapsed) : trim($sql);
}

/**
 * Tokenize executable SQL outside comments and string literals.
 *
 * @return list<array{type:string,value?:string}>
 */
function orange_restore_sql_tokenize_executable(string $sql): array
{
    $len = strlen($sql);
    $lexemes = [];
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($c === "\n" || $c === "\r") {
                $inLineComment = false;
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
                $i++;
                continue;
            }
            if ($c === '#') {
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
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
            continue;
        }

        if (!$inSingle && $c === '"') {
            if ($inDouble && $next === '"') {
                $i++;
                continue;
            }
            $inDouble = !$inDouble;
            continue;
        }

        if ($inSingle || $inDouble) {
            if ($inSingle && $c === '\\' && $next !== '') {
                $i++;
            }
            continue;
        }

        if ($c === '`') {
            $ident = orange_restore_sql_read_backtick_identifier($sql, $i);
            $lexemes[] = ['type' => 'ident', 'value' => $ident];
            continue;
        }

        if ($c === '.') {
            $lexemes[] = ['type' => 'dot'];
            continue;
        }

        if (ctype_space($c)) {
            continue;
        }

        if (preg_match('/[A-Za-z0-9_$]/', $c) === 1) {
            $start = $i;
            while ($i + 1 < $len && preg_match('/[A-Za-z0-9_$]/', $sql[$i + 1]) === 1) {
                $i++;
            }
            $lexemes[] = ['type' => 'ident', 'value' => substr($sql, $start, $i - $start + 1)];
            continue;
        }

        $lexemes[] = ['type' => 'other', 'value' => $c];
    }

    if ($inSingle || $inDouble || $inBlockComment) {
        throw new RuntimeException('Staging SQL import rejected unterminated string or comment.');
    }

    return $lexemes;
}

function orange_restore_sql_read_backtick_identifier(string $sql, int &$index): string
{
    $len = strlen($sql);
    $value = '';
    $index++;
    while ($index < $len) {
        $c = $sql[$index];
        if ($c === '`') {
            if ($index + 1 < $len && $sql[$index + 1] === '`') {
                $value .= '`';
                $index += 2;
                continue;
            }
            break;
        }
        $value .= $c;
        $index++;
    }

    return $value;
}

/**
 * @param list<array{type:string,value?:string}> $lexemes
 */
function orange_restore_sql_reject_forbidden_leading_keywords(array $lexemes): void
{
    $idents = [];
    foreach ($lexemes as $lexeme) {
        if (($lexeme['type'] ?? '') === 'ident') {
            $idents[] = strtoupper((string) ($lexeme['value'] ?? ''));
        }
    }

    if ($idents === []) {
        return;
    }

    if ($idents[0] === 'USE') {
        throw new RuntimeException(
            'Staging SQL import rejected USE database switch statement (production safety).'
        );
    }

    if ($idents[0] === 'CREATE' && ($idents[1] ?? '') === 'DATABASE') {
        throw new RuntimeException(
            'Staging SQL import rejected CREATE DATABASE statement (production safety).'
        );
    }

    if ($idents[0] === 'DROP' && ($idents[1] ?? '') === 'DATABASE') {
        throw new RuntimeException(
            'Staging SQL import rejected DROP DATABASE statement (production safety).'
        );
    }

    if ($idents[0] === 'ALTER' && ($idents[1] ?? '') === 'DATABASE') {
        throw new RuntimeException(
            'Staging SQL import rejected ALTER DATABASE statement (production safety).'
        );
    }
}

/**
 * Collect uppercase identifier keywords immediately before a lexeme index.
 *
 * @param list<array{type:string,value?:string}> $lexemes
 * @return list<string>
 */
function orange_restore_sql_identifiers_before(array $lexemes, int $beforeIndex, int $limit = 8): array
{
    $idents = [];
    for ($j = $beforeIndex - 1; $j >= 0 && count($idents) < $limit; $j--) {
        if (($lexemes[$j]['type'] ?? '') === 'ident') {
            array_unshift($idents, strtoupper((string) ($lexemes[$j]['value'] ?? '')));
        }
    }

    return $idents;
}

/**
 * @param list<string> $precedingIdents
 * @param list<string> $sequence
 */
function orange_restore_sql_idents_contain_ordered_sequence(array $precedingIdents, array $sequence): bool
{
    if ($sequence === []) {
        return false;
    }

    $pos = 0;
    foreach ($precedingIdents as $ident) {
        if ($ident === $sequence[$pos]) {
            $pos++;
            if ($pos === count($sequence)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * True when a schema-qualified object name may legally appear at this lexeme index.
 *
 * @param list<array{type:string,value?:string}> $lexemes
 */
function orange_restore_sql_is_schema_qualified_object_context(array $lexemes, int $schemaLexIndex): bool
{
    $preceding = orange_restore_sql_identifiers_before($lexemes, $schemaLexIndex);
    if ($preceding === []) {
        return false;
    }

    $last = $preceding[count($preceding) - 1];

    if ($last === 'INTO') {
        foreach ($preceding as $ident) {
            if ($ident === 'INSERT' || $ident === 'REPLACE') {
                return true;
            }
        }

        return false;
    }

    if ($last === 'FROM') {
        return true;
    }

    if ($last === 'UPDATE') {
        return true;
    }

    if ($last === 'JOIN' || $last === 'STRAIGHT_JOIN') {
        return true;
    }

    if ($last === 'REFERENCES') {
        return true;
    }

    if ($last === 'TRUNCATE') {
        return true;
    }

    if ($last === 'TO') {
        return orange_restore_sql_idents_contain_ordered_sequence($preceding, ['RENAME', 'TABLE']);
    }

    if ($last === 'TABLES') {
        return in_array('LOCK', $preceding, true);
    }

    if ($last === 'EXISTS') {
        return orange_restore_sql_idents_contain_ordered_sequence($preceding, ['CREATE', 'TABLE'])
            || orange_restore_sql_idents_contain_ordered_sequence($preceding, ['DROP', 'TABLE']);
    }

    if ($last === 'TABLE') {
        foreach (['CREATE', 'ALTER', 'DROP', 'RENAME', 'TRUNCATE'] as $keyword) {
            if (in_array($keyword, $preceding, true)) {
                return true;
            }
        }

        return false;
    }

    return false;
}

/**
 * @param list<array{type:string,value?:string}> $lexemes
 */
function orange_restore_sql_reject_malformed_qualified_object(
    array $lexemes,
    int $schemaLexIndex,
    string $stagingDb
): void {
    unset($stagingDb);

    if (($lexemes[$schemaLexIndex + 1]['type'] ?? '') !== 'dot') {
        return;
    }

    if (($lexemes[$schemaLexIndex + 2]['type'] ?? '') !== 'ident') {
        $schema = (string) ($lexemes[$schemaLexIndex]['value'] ?? '');

        throw new RuntimeException(
            'Staging SQL import rejected malformed qualified object name after '
            . $schema
            . '. (production safety).'
        );
    }
}

/**
 * Reject schema-qualified object references outside the staging database.
 *
 * Only inspects ident.ident shapes in executable object-name positions (INSERT INTO, FROM, JOIN, …).
 * Numeric literals, DECIMAL(p,s), alias.column, and string/comment contents are ignored.
 *
 * @param list<array{type:string,value?:string}> $lexemes
 */
function orange_restore_sql_reject_cross_database_references_lexemes(
    array $lexemes,
    string $stagingDb,
    string $productionDb
): void {
    unset($productionDb);

    $count = count($lexemes);
    for ($i = 0; $i < $count; $i++) {
        if (($lexemes[$i]['type'] ?? '') !== 'ident') {
            continue;
        }

        if (!orange_restore_sql_is_schema_qualified_object_context($lexemes, $i)) {
            continue;
        }

        if (($lexemes[$i + 1]['type'] ?? '') === 'dot') {
            orange_restore_sql_reject_malformed_qualified_object($lexemes, $i, $stagingDb);

            $schema = (string) ($lexemes[$i]['value'] ?? '');
            $object = (string) ($lexemes[$i + 2]['value'] ?? '');
            if ($schema === '' || $object === '') {
                throw new RuntimeException(
                    'Staging SQL import rejected empty schema-qualified object name (production safety).'
                );
            }
            if (strcasecmp($schema, $stagingDb) !== 0) {
                throw new RuntimeException(
                    'Staging SQL import rejected cross-database reference '
                    . $schema
                    . '.'
                    . $object
                    . ' (production safety).'
                );
            }

            $i += 2;
            continue;
        }

        // Unqualified object name in object context — allowed.
    }
}

function orange_restore_sql_strip_leading_comments(string $sql): string
{
    $remaining = ltrim($sql);
    while ($remaining !== '') {
        if (str_starts_with($remaining, '--') || str_starts_with($remaining, '#')) {
            $pos = strpos($remaining, "\n");
            if ($pos === false) {
                return '';
            }
            $remaining = ltrim(substr($remaining, $pos + 1));
            continue;
        }
        if (str_starts_with($remaining, '/*')) {
            $end = strpos($remaining, '*/');
            if ($end === false) {
                throw new RuntimeException('Staging SQL import rejected unterminated block comment.');
            }
            $remaining = ltrim(substr($remaining, $end + 2));
            continue;
        }
        break;
    }

    return $remaining;
}

/**
 * Stream-scan a gzip SQL dump for patterns incompatible with Phase 2B.1 staging import.
 *
 * @return array{ok:bool,error:?string,hits:list<string>}
 */
function orange_restore_sql_scan_gzip_forbidden_patterns(
    string $gzipPath,
    string $stagingDb,
    string $productionDb
): array {
    unset($productionDb);

    if (!is_file($gzipPath)) {
        return ['ok' => false, 'error' => 'SQL gzip file missing', 'hits' => []];
    }
    if (!function_exists('gzopen')) {
        return ['ok' => false, 'error' => 'gzopen unavailable', 'hits' => []];
    }

    $handle = @gzopen($gzipPath, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'Cannot open gzip SQL file', 'hits' => []];
    }

    $window = '';
    $hits = [];
    $patterns = [
        'DELIMITER' => '/\bDELIMITER\b/i',
        'USE database switch' => '/\bUSE\s+/i',
        'CREATE DATABASE' => '/\bCREATE\s+DATABASE\b/i',
        'DROP DATABASE' => '/\bDROP\s+DATABASE\b/i',
        'ALTER DATABASE' => '/\bALTER\s+DATABASE\b/i',
    ];

    try {
        while (!gzeof($handle)) {
            $chunk = gzread($handle, 65536);
            if ($chunk === false) {
                gzclose($handle);

                return ['ok' => false, 'error' => 'Corrupt gzip stream during safety scan', 'hits' => $hits];
            }
            if ($chunk === '') {
                continue;
            }
            $window .= $chunk;
            if (strlen($window) > 131072) {
                $window = substr($window, -131072);
            }
            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $window) === 1 && !in_array($label, $hits, true)) {
                    $hits[] = $label;
                }
            }
        }
    } finally {
        gzclose($handle);
    }

    if ($hits !== []) {
        return [
            'ok' => false,
            'error' => 'SQL dump contains forbidden pattern(s) for Phase 2B.1 staging import: '
                . implode(', ', $hits)
                . ' (staging_db=' . $stagingDb . ').',
            'hits' => $hits,
        ];
    }

    return ['ok' => true, 'error' => null, 'hits' => []];
}

function orange_restore_sql_is_comment_only(string $sql): bool
{
    $lines = preg_split("/\r\n|\n|\r/", trim($sql)) ?: [];
    foreach ($lines as $line) {
        $trim = trim((string) $line);
        if ($trim === '') {
            continue;
        }
        if (!str_starts_with($trim, '--') && !str_starts_with($trim, '#')) {
            return false;
        }
    }

    return true;
}
