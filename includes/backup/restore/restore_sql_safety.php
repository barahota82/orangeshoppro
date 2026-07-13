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

    $upper = strtoupper(ltrim($normalized));

    if (preg_match('/^DELIMITER\b/i', $normalized) === 1) {
        throw new RuntimeException(
            'Staging SQL import rejected DELIMITER directive (unsupported in Phase 2B.1).'
        );
    }

    $forbiddenStarts = [
        'USE ' => 'USE database switch',
        'CREATE DATABASE' => 'CREATE DATABASE',
        'DROP DATABASE' => 'DROP DATABASE',
        'ALTER DATABASE' => 'ALTER DATABASE',
    ];
    foreach ($forbiddenStarts as $prefix => $label) {
        if (str_starts_with($upper, $prefix)) {
            throw new RuntimeException(
                'Staging SQL import rejected ' . $label . ' statement (production safety).'
            );
        }
    }

    orange_restore_sql_reject_cross_database_references($normalized, $stagingDb, $productionDb);
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
 * Reject `other_db`.`table` and other_db.table where other_db is not staging.
 *
 * @throws RuntimeException
 */
function orange_restore_sql_reject_cross_database_references(
    string $sql,
    string $stagingDb,
    string $productionDb
): void {
    unset($productionDb);

    if (preg_match_all('/`([^`]+)`\.`([^`]+)`/', $sql, $backtickMatches, PREG_SET_ORDER)) {
        foreach ($backtickMatches as $match) {
            $schema = (string) ($match[1] ?? '');
            if ($schema !== '' && strcasecmp($schema, $stagingDb) !== 0) {
                throw new RuntimeException(
                    'Staging SQL import rejected cross-database reference `'
                    . $schema
                    . '`.`'
                    . (string) ($match[2] ?? '')
                    . '` (production safety).'
                );
            }
        }
    }

    if (preg_match_all('/\b([A-Za-z0-9_]+)\.`([^`]+)`/', $sql, $mixedMatches, PREG_SET_ORDER)) {
        foreach ($mixedMatches as $match) {
            $schema = (string) ($match[1] ?? '');
            if (in_array(strtoupper($schema), ['SET', 'VALUES', 'INTO', 'FROM', 'JOIN', 'TABLE', 'INDEX', 'KEY'], true)) {
                continue;
            }
            if ($schema !== '' && strcasecmp($schema, $stagingDb) !== 0) {
                throw new RuntimeException(
                    'Staging SQL import rejected cross-database reference '
                    . $schema
                    . '.`'
                    . (string) ($match[2] ?? '')
                    . '` (production safety).'
                );
            }
        }
    }
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
        'USE database switch' => '/\bUSE\s+[`\'"]?(?:' . preg_quote($productionDb, '/') . ')[`\'"]?\s*;/i',
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
