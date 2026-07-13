<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_staging_target.php';

/**
 * Stream-import a gzip SQL dump into staging PDO (no full-file decompression in memory).
 *
 * @return array{ok:bool,statements_executed:int,bytes_read:int,error:?string}
 */
function orange_restore_sql_runner_import_gzip(PDO $pdo, string $gzipPath, ?callable $log = null): array
{
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
                $split = orange_restore_sql_runner_split_next_statement($buffer);
                if ($split === null) {
                    break;
                }
                $buffer = $split['remainder'];
                $statement = trim($split['statement']);
                if ($statement === '' || orange_restore_sql_runner_is_comment_only($statement)) {
                    continue;
                }
                try {
                    $pdo->exec($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException('SQL import failed: ' . $e->getMessage());
                }
                $statementsExecuted++;
                if ($statementsExecuted - $lastProgressAt >= 500) {
                    $log('SQL import... progress statements=' . (string) $statementsExecuted . ' bytes=' . (string) $bytesRead);
                    $lastProgressAt = $statementsExecuted;
                }
            }
        }

        orange_restore_sql_runner_split_next_statement($buffer, true);
        $tail = trim($buffer);
        if ($tail !== '' && !orange_restore_sql_runner_is_comment_only($tail)) {
            throw new RuntimeException('SQL import ended with incomplete statement in gzip stream.');
        }
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
    $log('SQL import... OK (statements=' . (string) $statementsExecuted . ', bytes=' . (string) $bytesRead . ')');

    return [
        'ok' => true,
        'statements_executed' => $statementsExecuted,
        'bytes_read' => $bytesRead,
        'error' => null,
    ];
}

/**
 * @return array{statement:string,remainder:string}|null
 */
function orange_restore_sql_runner_split_next_statement(string $buffer, bool $final = false): ?array
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
        if (!$final) {
            return null;
        }
        throw new RuntimeException('SQL stream contains unterminated string or comment.');
    }

    return null;
}

function orange_restore_sql_runner_is_comment_only(string $sql): bool
{
    $lines = preg_split("/\r\n|\n|\r/", trim($sql)) ?: [];
    foreach ($lines as $line) {
        $trim = trim((string) $line);
        if ($trim === '') {
            continue;
        }
        if (!str_starts_with($trim, '--')) {
            return false;
        }
    }

    return true;
}
