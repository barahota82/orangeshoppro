<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_sql_safety.php';

/**
 * Stream-import a gzip SQL dump into staging PDO (no full-file decompression in memory).
 *
 * @return array{ok:bool,statements_executed:int,bytes_read:int,error:?string}
 */
function orange_restore_sql_runner_import_gzip(
    PDO $pdo,
    string $gzipPath,
    string $stagingDb,
    string $productionDb,
    ?callable $log = null
): array {
    if (!is_file($gzipPath)) {
        return ['ok' => false, 'statements_executed' => 0, 'bytes_read' => 0, 'error' => 'SQL gzip file missing'];
    }
    if (!function_exists('gzopen')) {
        return ['ok' => false, 'statements_executed' => 0, 'bytes_read' => 0, 'error' => 'gzopen unavailable'];
    }

    $handle = @gzopen($gzipPath, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'statements_executed' => 0, 'bytes_read' => 0, 'error' => 'Cannot open gzip SQL file'];
    }

    $log ??= static function (string $message): void {
        orange_restore_log($message);
    };

    $log('SQL import... START');
    orange_restore_sql_assert_session_database($pdo, $stagingDb);

    $buffer = '';
    $bytesRead = 0;
    $statementsExecuted = 0;
    $lastProgressAt = 0;

    try {
        while (!gzeof($handle)) {
            $chunk = gzread($handle, 65536);
            if ($chunk === false) {
                throw new RuntimeException('Corrupt gzip stream while reading SQL dump.');
            }
            if ($chunk === '') {
                continue;
            }
            $bytesRead += strlen($chunk);
            $buffer .= $chunk;

            while (true) {
                $split = orange_restore_sql_runner_split_next_statement($buffer, true);
                if ($split === null) {
                    break;
                }
                $buffer = $split['remainder'];
                $statement = trim($split['statement']);
                if ($statement === '' || orange_restore_sql_is_comment_only($statement)) {
                    continue;
                }

                orange_restore_sql_assert_session_database($pdo, $stagingDb);
                orange_restore_sql_validate_statement_for_staging($statement, $stagingDb, $productionDb);

                try {
                    $pdo->exec($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException('SQL import failed: ' . $e->getMessage());
                }

                orange_restore_sql_assert_session_database($pdo, $stagingDb);
                $statementsExecuted++;
                if ($statementsExecuted - $lastProgressAt >= 500) {
                    $log('SQL import... progress statements=' . (string) $statementsExecuted . ' bytes=' . (string) $bytesRead);
                    $lastProgressAt = $statementsExecuted;
                }
            }
        }

        orange_restore_sql_runner_assert_final_tail_complete($buffer, 'gzip stream');
    } catch (Throwable $e) {
        gzclose($handle);

        return [
            'ok' => false,
            'statements_executed' => $statementsExecuted,
            'bytes_read' => $bytesRead,
            'error' => $e->getMessage(),
        ];
    }

    gzclose($handle);
    orange_restore_sql_assert_session_database($pdo, $stagingDb);
    $log('SQL import... OK (statements=' . (string) $statementsExecuted . ', bytes=' . (string) $bytesRead . ')');

    return [
        'ok' => true,
        'statements_executed' => $statementsExecuted,
        'bytes_read' => $bytesRead,
        'error' => null,
    ];
}

/**
 * Import a plain SQL file into staging (CRP chunks — no gzip).
 *
 * @return array{ok:bool,statements_executed:int,bytes_read:int,error:?string}
 */
function orange_restore_sql_runner_import_sql_file(
    PDO $pdo,
    string $sqlPath,
    string $stagingDb,
    string $productionDb,
    ?callable $log = null
): array {
    if (!is_file($sqlPath)) {
        return ['ok' => false, 'statements_executed' => 0, 'bytes_read' => 0, 'error' => 'SQL file missing: ' . $sqlPath];
    }

    $handle = @fopen($sqlPath, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'statements_executed' => 0, 'bytes_read' => 0, 'error' => 'Cannot open SQL file: ' . $sqlPath];
    }

    $log ??= static function (string $message): void {
        orange_restore_log($message);
    };

    $log('SQL import chunk... START (' . basename($sqlPath) . ')');
    orange_restore_sql_assert_session_database($pdo, $stagingDb);

    $statementsExecuted = 0;
    $bytesRead = 0;
    $buffer = '';

    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                throw new RuntimeException('Cannot read SQL file: ' . basename($sqlPath));
            }
            if ($chunk === '') {
                continue;
            }
            $bytesRead += strlen($chunk);
            $buffer .= $chunk;

            while (true) {
                $split = orange_restore_sql_runner_split_next_statement($buffer, true);
                if ($split === null) {
                    break;
                }
                $buffer = $split['remainder'];
                $statement = trim($split['statement']);
                if ($statement === '' || orange_restore_sql_is_comment_only($statement)) {
                    continue;
                }

                orange_restore_sql_assert_session_database($pdo, $stagingDb);
                orange_restore_sql_validate_statement_for_staging($statement, $stagingDb, $productionDb);

                try {
                    $pdo->exec($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException('SQL import failed in ' . basename($sqlPath) . ': ' . $e->getMessage());
                }

                orange_restore_sql_assert_session_database($pdo, $stagingDb);
                $statementsExecuted++;
            }
        }

        orange_restore_sql_runner_assert_final_tail_complete($buffer, basename($sqlPath));
    } catch (Throwable $e) {
        fclose($handle);

        return [
            'ok' => false,
            'statements_executed' => $statementsExecuted,
            'bytes_read' => $bytesRead,
            'error' => $e->getMessage(),
        ];
    }
    fclose($handle);

    orange_restore_sql_assert_session_database($pdo, $stagingDb);
    $log('SQL import chunk... OK (' . basename($sqlPath) . ', statements=' . (string) $statementsExecuted . ')');

    return [
        'ok' => true,
        'statements_executed' => $statementsExecuted,
        'bytes_read' => $bytesRead,
        'error' => null,
    ];
}

/**
 * Import ordered CRP SQL chunk files into staging.
 *
 * @param list<string> $sqlFiles
 * @return array{ok:bool,files_imported:int,statements_executed:int,bytes_read:int,error:?string,chunks:list<array<string,mixed>>}
 */
function orange_restore_sql_runner_import_country_chunks(
    PDO $pdo,
    array $sqlFiles,
    string $stagingDb,
    string $productionDb,
    ?callable $log = null
): array {
    $filesImported = 0;
    $statementsExecuted = 0;
    $bytesRead = 0;
    $chunks = [];

    foreach ($sqlFiles as $sqlPath) {
        $result = orange_restore_sql_runner_import_sql_file($pdo, $sqlPath, $stagingDb, $productionDb, $log);
        $chunks[] = [
            'file' => basename($sqlPath),
            'ok' => $result['ok'],
            'statements_executed' => $result['statements_executed'],
            'bytes_read' => $result['bytes_read'],
            'error' => $result['error'],
        ];
        if (!$result['ok']) {
            return [
                'ok' => false,
                'files_imported' => $filesImported,
                'statements_executed' => $statementsExecuted,
                'bytes_read' => $bytesRead,
                'error' => (string) ($result['error'] ?? 'Country SQL chunk import failed'),
                'chunks' => $chunks,
            ];
        }
        $filesImported++;
        $statementsExecuted += (int) $result['statements_executed'];
        $bytesRead += (int) $result['bytes_read'];
    }

    return [
        'ok' => true,
        'files_imported' => $filesImported,
        'statements_executed' => $statementsExecuted,
        'bytes_read' => $bytesRead,
        'error' => null,
        'chunks' => $chunks,
    ];
}

function orange_restore_sql_runner_assert_final_tail_complete(string $buffer, string $sourceLabel): void
{
    $tail = trim($buffer);
    if ($tail === '' || orange_restore_sql_is_comment_only($tail)) {
        return;
    }

    orange_restore_sql_runner_split_next_statement($buffer, false);

    throw new RuntimeException('SQL import ended with incomplete statement in ' . $sourceLabel . '.');
}

/**
 * @return array{statement:string,remainder:string}|null
 */
function orange_restore_sql_runner_split_next_statement(string $buffer, bool $allowIncompleteTail = false): ?array
{
    $len = strlen($buffer);
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;
    $statement = '';

    for ($i = 0; $i < $len; $i++) {
        $c = $buffer[$i];
        $next = $i + 1 < $len ? $buffer[$i + 1] : '';

        if ($inLineComment) {
            $statement .= $c;
            if ($c === "\n" || $c === "\r") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            $statement .= $c;
            if ($c === '*' && $next === '/') {
                $statement .= $next;
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble) {
            if ($c === '-' && $next === '-') {
                $inLineComment = true;
                $statement .= $c;
                continue;
            }
            if ($c === '#') {
                $inLineComment = true;
                $statement .= $c;
                continue;
            }
            if ($c === '/' && $next === '*') {
                $inBlockComment = true;
                $statement .= $c;
                continue;
            }
        }

        if (!$inDouble && $c === "'") {
            if ($inSingle && $next === "'") {
                $statement .= "''";
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
            $statement .= $c;
            continue;
        }

        if (!$inSingle && $c === '"') {
            if ($inDouble && $next === '"') {
                $statement .= '""';
                $i++;
                continue;
            }
            $inDouble = !$inDouble;
            $statement .= $c;
            continue;
        }

        if ($inSingle && $c === '\\' && $next !== '') {
            $statement .= $c . $next;
            $i++;
            continue;
        }

        if (!$inSingle && !$inDouble && $c === ';') {
            return [
                'statement' => $statement,
                'remainder' => substr($buffer, $i + 1),
            ];
        }

        $statement .= $c;
    }

    if ($inSingle || $inDouble || $inBlockComment) {
        if ($allowIncompleteTail) {
            return null;
        }

        throw new RuntimeException('SQL stream contains unterminated string or comment.');
    }

    return null;
}
