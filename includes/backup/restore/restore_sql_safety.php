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
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw new RuntimeException('Staging SQL import rejected unterminated string or comment.');
                }
                if ($i + 2 < $len && $sql[$i + 2] === '!') {
                    $body = substr($sql, $i + 3, $end - ($i + 3));
                    $body = preg_replace('/^\d{5,6}\s*/', '', $body) ?: $body;
                    foreach (orange_restore_sql_tokenize_executable($body) as $innerLexeme) {
                        $lexemes[] = $innerLexeme;
                    }
                }
                $i = $end + 1;
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

        if (ctype_digit($c)) {
            $start = $i;
            while ($i + 1 < $len && ctype_digit($sql[$i + 1])) {
                $i++;
            }
            if ($i + 2 < $len && $sql[$i + 1] === '.' && ctype_digit($sql[$i + 2])) {
                $i += 2;
                while ($i + 1 < $len && ctype_digit($sql[$i + 1])) {
                    $i++;
                }
            }
            if ($i + 1 < $len && ($sql[$i + 1] === 'e' || $sql[$i + 1] === 'E')) {
                $exp = $i + 2;
                if ($exp < $len && ($sql[$exp] === '+' || $sql[$exp] === '-')) {
                    $exp++;
                }
                if ($exp < $len && ctype_digit($sql[$exp])) {
                    $i = $exp;
                    while ($i + 1 < $len && ctype_digit($sql[$i + 1])) {
                        $i++;
                    }
                }
            }
            $lexemes[] = ['type' => 'number', 'value' => substr($sql, $start, $i - $start + 1)];
            continue;
        }

        if (preg_match('/[A-Za-z_$]/', $c) === 1) {
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
 * @param list<array{type:string,value?:string}> $lexemes
 */
function orange_restore_sql_reject_cross_database_references_lexemes(
    array $lexemes,
    string $stagingDb,
    string $productionDb
): void {
    unset($productionDb);

    $count = count($lexemes);
    for ($i = 0; $i + 2 < $count; $i++) {
        if (($lexemes[$i]['type'] ?? '') !== 'ident') {
            continue;
        }
        if (($lexemes[$i + 1]['type'] ?? '') !== 'dot') {
            continue;
        }
        if (($lexemes[$i + 2]['type'] ?? '') !== 'ident') {
            continue;
        }

        $schema = (string) ($lexemes[$i]['value'] ?? '');
        $object = (string) ($lexemes[$i + 2]['value'] ?? '');
        if ($schema === '' || $object === '') {
            continue;
        }
        if (orange_restore_sql_is_reserved_schema_noise($schema)) {
            continue;
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
    }
}

function orange_restore_sql_is_reserved_schema_noise(string $schema): bool
{
    return in_array(strtoupper($schema), [
        'SET', 'VALUES', 'INTO', 'FROM', 'JOIN', 'TABLE', 'INDEX', 'KEY', 'WHERE', 'ON', 'AS',
    ], true);
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
        if (str_starts_with($remaining, '/*!')) {
            break;
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
        'DELIMITER' => '/(?:^|[;\r\n])\s*DELIMITER\b/i',
        'USE database switch' => '/(?:^|[;\r\n])\s*USE\s+/i',
        'CREATE DATABASE' => '/(?:^|[;\r\n])\s*CREATE\s+DATABASE\b/i',
        'DROP DATABASE' => '/(?:^|[;\r\n])\s*DROP\s+DATABASE\b/i',
        'ALTER DATABASE' => '/(?:^|[;\r\n])\s*ALTER\s+DATABASE\b/i',
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
