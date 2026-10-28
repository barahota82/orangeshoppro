<?php

declare(strict_types=1);

/**
 * KEEP41 batch only. Check-only client probe.
 * Does not open a database connection, does not read SQL, does not take locks.
 * CLI only. A web request stops before any probe.
 *
 * Hosting launch is not the backup button. No arguments: this check.
 * Any other argument is refused.
 */

if (PHP_SAPI !== 'cli') {
    echo "CLI_ONLY\n";
    exit(2);
}

/**
 * A captured -1 is not success. A real status code wins; proc_close is only a fallback.
 */
function keep41_resolve_exit_code(int $statusCode, int $closeCode): int
{
    if ($statusCode >= 0) {
        return $statusCode;
    }
    if ($closeCode >= 0) {
        return $closeCode;
    }

    return -1;
}

function keep41_proc_open_blocked(): bool
{
    if (!function_exists('proc_open')) {
        return true;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

    return in_array('proc_open', $disabled, true);
}

/**
 * @return array{exit_code:int,stdout:string,stderr:string,timed_out:bool}
 */
function keep41_redact_text(string $text): string
{
    $text = str_replace("\0", '', $text);
    $text = preg_replace('/([a-z][a-z0-9+.-]*:\/\/)[^\s@\/]+:[^\s@\/]+@/i', '$1[redacted]@', $text) ?? $text;
    $text = preg_replace('/\b(password|passwd|pwd|secret|token|api[_-]?key)\b(\s*[=:]\s*)(\S+)/i', '$1$2[redacted]', $text) ?? $text;
    $text = preg_replace('/\b(identified\s+by\s+)(\S+)/i', '$1[redacted]', $text) ?? $text;
    $text = preg_replace('/(--password(?:=|\s+))(\S+)/i', '$1[redacted]', $text) ?? $text;

    return $text;
}

function keep41_one_line(string $text, int $limit = 400): string
{
    $text = keep41_redact_text($text);
    $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $text = trim($text);
    if (strlen($text) > $limit) {
        return substr($text, 0, $limit) . '...';
    }

    return $text;
}

function keep41_capture(array $command, int $timeoutSeconds = 15): array
{
    $empty = ['exit_code' => -1, 'stdout' => '', 'stderr' => '', 'timed_out' => false];
    if (keep41_proc_open_blocked()) {
        $empty['exit_code'] = 127;
        $empty['stderr'] = 'proc_open_unavailable';

        return $empty;
    }

    $stdoutFile = tempnam(sys_get_temp_dir(), 'k41o');
    $stderrFile = tempnam(sys_get_temp_dir(), 'k41e');
    if ($stdoutFile === false || $stderrFile === false) {
        $empty['exit_code'] = 127;
        $empty['stderr'] = 'proc_open_failed';

        return $empty;
    }
    $stdin = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $pipes = [];
    $opened = '';
    set_error_handler(static function (int $severity, string $message) use (&$opened): bool {
        $opened .= $message . "\n";

        return true;
    });
    try {
        $process = proc_open(
            $command,
            [
                0 => ['file', $stdin, 'r'],
                1 => ['file', $stdoutFile, 'w'],
                2 => ['file', $stderrFile, 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
    } finally {
        restore_error_handler();
    }
    if (!is_resource($process)) {
        @unlink($stdoutFile);
        @unlink($stderrFile);
        $empty['exit_code'] = 127;
        $empty['stderr'] = trim('proc_open_failed ' . keep41_one_line($opened));

        return $empty;
    }
    $started = time();
    $timedOut = false;
    $statusCode = -1;
    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            $statusCode = (int) $status['exitcode'];
            break;
        }
        if ((time() - $started) >= $timeoutSeconds) {
            $timedOut = true;
            $pid = (int) ($status['pid'] ?? 0);
            proc_terminate($process);
            if ($pid > 0 && DIRECTORY_SEPARATOR === '\\') {
                $killPipes = [];
                $killer = proc_open(
                    ['taskkill.exe', '/F', '/PID', (string) $pid],
                    [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
                    $killPipes,
                    null,
                    null,
                    ['bypass_shell' => true]
                );
                if (is_resource($killer)) {
                    proc_close($killer);
                }
            }
            $deadline = microtime(true) + 2.0;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $statusCode = (int) $status['exitcode'];
                    break;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            break;
        }
        usleep(100000);
    }
    $stdout = is_file($stdoutFile) ? (string) file_get_contents($stdoutFile) : '';
    $stderr = is_file($stderrFile) ? (string) file_get_contents($stderrFile) : '';
    if ($timedOut) {
        $stderr .= 'timeout';
    }
    @unlink($stdoutFile);
    @unlink($stderrFile);
    $closeCode = proc_close($process);
    $exitCode = keep41_resolve_exit_code($statusCode, is_int($closeCode) ? $closeCode : -1);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => $timedOut,
    ];
}

/**
 * Same Plesk and Program Files bins the backup probe searches for mysqldump.exe.
 *
 * @return list<string>
 */
function keep41_client_candidates(): array
{
    $bases = [];
    foreach (['plesk_dir', 'PLESK_DIR'] as $envKey) {
        $value = getenv($envKey);
        if (is_string($value) && trim($value) !== '') {
            $bases[] = rtrim(str_replace('/', '\\', trim($value)), '\\');
        }
    }
    $bases = array_merge($bases, [
        'C:\\Program Files (x86)\\Plesk',
        'C:\\Program Files (x86)\\Parallels\\Plesk',
        'C:\\Program Files\\Plesk',
        'C:\\Program Files\\Parallels\\Plesk',
    ]);
    $dirs = [];
    foreach (array_values(array_unique($bases)) as $base) {
        foreach (['MySQL', 'MySql', 'Mysql'] as $mysqlDir) {
            $dirs[] = $base . '\\' . $mysqlDir . '\\bin';
        }
        $dirs[] = $base . '\\Databases\\MySQL\\bin';
        $dirs[] = $base . '\\Databases\\MariaDB\\bin';
    }
    foreach ([
        'C:\\Program Files\\MariaDB 11.5\\bin',
        'C:\\Program Files\\MariaDB 11.4\\bin',
        'C:\\Program Files\\MariaDB 11.3\\bin',
        'C:\\Program Files\\MariaDB 11.2\\bin',
        'C:\\Program Files\\MariaDB 10.11\\bin',
        'C:\\Program Files\\MariaDB 10.10\\bin',
        'C:\\Program Files\\MariaDB 10.6\\bin',
        'C:\\Program Files\\MariaDB 10.5\\bin',
        'C:\\Program Files\\MariaDB 10.4\\bin',
        'C:\\Program Files\\MariaDB\\bin',
        'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin',
    ] as $dir) {
        $dirs[] = $dir;
    }

    $paths = [];
    foreach (['mariadb.exe', 'mysql.exe'] as $name) {
        foreach ($dirs as $dir) {
            $paths[] = $dir . '\\' . $name;
        }
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        foreach (['/usr/bin/mariadb', '/usr/bin/mysql', '/usr/local/bin/mariadb', '/usr/local/bin/mysql'] as $path) {
            $paths[] = $path;
        }
    }

    return $paths;
}

/**
 * @param array{exit_code:int,stdout:string,stderr:string,timed_out:bool} $found
 * @return array{ok:bool,paths:list<string>,reason:string}
 */
function keep41_where_from_capture(array $found): array
{
    $stderr = (string) ($found['stderr'] ?? '');
    $code = (int) ($found['exit_code'] ?? -1);
    $blocked = !empty($found['timed_out'])
        || $code < 0
        || str_contains($stderr, 'proc_open_unavailable')
        || str_contains($stderr, 'proc_open_failed');
    if ($blocked || ($code !== 0 && $code !== 1)) {
        return ['ok' => false, 'paths' => [], 'reason' => 'path_search_failed'];
    }
    if ($code === 1) {
        return ['ok' => true, 'paths' => [], 'reason' => 'path_search_empty'];
    }
    $paths = [];
    foreach (preg_split('/\R/', (string) ($found['stdout'] ?? '')) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_contains(strtolower($line), 'could not find') || str_contains(strtolower($line), 'info:')) {
            continue;
        }
        $paths[] = $line;
    }

    return ['ok' => true, 'paths' => $paths, 'reason' => 'path_search_ok'];
}

/**
 * @return array{ok:bool,paths:list<string>,reason:string}
 */
function keep41_where(string $name): array
{
    $finder = DIRECTORY_SEPARATOR === '\\' ? 'where.exe' : 'which';
    $command = [$finder, $name];
    $found = keep41_capture($command, 10);
    $parsed = keep41_where_from_capture($found);
    $parsed['command'] = implode(' ', $command);
    $parsed['exit_code'] = (int) ($found['exit_code'] ?? -1);
    $parsed['timed_out'] = !empty($found['timed_out']);
    $parsed['stdout'] = keep41_one_line((string) ($found['stdout'] ?? ''));
    $parsed['stderr'] = keep41_one_line((string) ($found['stderr'] ?? ''));

    return $parsed;
}

function keep41_version_line(string $stdout): string
{
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            return substr($line, 0, 180);
        }
    }

    return '';
}

function keep41_tried_connect(string $text): bool
{
    $lower = strtolower($text);

    return str_contains($lower, "can't connect")
        || str_contains($lower, 'cannot connect')
        || str_contains($lower, 'access denied')
        || str_contains($lower, 'error 1045')
        || str_contains($lower, 'error 2002')
        || str_contains($lower, 'error 2003')
        || str_contains($lower, 'error 2005');
}

/**
 * @return array{path:string,exists:bool,version:string,abort:string,reason:string}
 */
function keep41_probe(string $path): array
{
    $row = ['path' => $path, 'exists' => false, 'version' => '', 'abort' => 'no', 'reason' => 'not_found'];
    if (!is_file($path)) {
        return $row;
    }
    $row['exists'] = true;
    $version = keep41_capture([$path, '--no-defaults', '--version']);
    if (str_contains($version['stderr'], 'proc_open_unavailable')) {
        $row['reason'] = 'check_unavailable';

        return $row;
    }
    if (str_contains($version['stderr'], 'proc_open_failed')) {
        $row['reason'] = 'run_failed';

        return $row;
    }
    if ($version['timed_out'] || keep41_tried_connect($version['stdout'] . $version['stderr'])) {
        $row['reason'] = $version['timed_out'] ? 'version_timeout' : 'version_connect_attempt';

        return $row;
    }
    if ($version['exit_code'] !== 0) {
        $row['reason'] = 'version_failed';

        return $row;
    }
    $row['version'] = keep41_version_line($version['stdout']);
    $help = keep41_capture([$path, '--no-defaults', '--help']);
    $helpText = $help['stdout'] . "\n" . $help['stderr'];
    if (str_contains($help['stderr'], 'proc_open_unavailable') || str_contains($help['stderr'], 'proc_open_failed')) {
        $row['reason'] = str_contains($help['stderr'], 'proc_open_unavailable') ? 'check_unavailable' : 'run_failed';

        return $row;
    }
    if ($help['timed_out']) {
        $row['reason'] = 'flag_timeout';

        return $row;
    }
    if (keep41_tried_connect($helpText)) {
        $row['reason'] = 'help_connect_attempt';

        return $row;
    }
    if ($help['exit_code'] !== 0) {
        $row['reason'] = 'help_failed';

        return $row;
    }
    if (!str_contains($helpText, 'abort-source-on-error')) {
        $row['reason'] = 'help_missing_option';

        return $row;
    }
    $flag = keep41_capture([$path, '--no-defaults', '--abort-source-on-error', '--version']);
    $flagText = strtolower($flag['stdout'] . "\n" . $flag['stderr']);
    if ($flag['timed_out']) {
        $row['reason'] = 'flag_timeout';

        return $row;
    }
    if (str_contains($flag['stderr'], 'proc_open_failed') || str_contains($flag['stderr'], 'proc_open_unavailable')) {
        $row['reason'] = 'run_failed';

        return $row;
    }
    if (keep41_tried_connect($flagText)) {
        $row['reason'] = 'flag_connect_attempt';

        return $row;
    }
    $flagOk = $flag['exit_code'] === 0
        && !str_contains($flagText, 'unknown option')
        && !str_contains($flagText, 'unknown variable');
    if ($flagOk) {
        $row['abort'] = 'yes';
        $row['reason'] = 'compatible';
        $flagVersion = keep41_version_line($flag['stdout']);
        if ($flagVersion !== '') {
            $row['version'] = $flagVersion;
        }

        return $row;
    }
    $row['reason'] = 'flag_rejected';

    return $row;
}

/**
 * @return array{path_search_failed:bool,examined:list<array{path:string,exists:bool,version:string,abort:string,reason:string}>,chosen:?array{path:string,exists:bool,version:string,abort:string,reason:string}}
 */
function keep41_scan(): array
{
    $ordered = keep41_client_candidates();
    $pathSearchFailed = false;
    $pathSearchDiag = [];
    $names = DIRECTORY_SEPARATOR === '\\' ? ['mariadb.exe', 'mysql.exe'] : ['mariadb', 'mysql'];
    foreach ($names as $name) {
        $where = keep41_where($name);
        if (!$where['ok']) {
            $pathSearchFailed = true;
            $pathSearchDiag[] = $where;
            break;
        }
        foreach ($where['paths'] as $path) {
            $ordered[] = $path;
        }
    }

    $seen = [];
    $unique = [];
    foreach ($ordered as $path) {
        $key = strtolower(str_replace('/', '\\', $path));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $path;
    }

    $examined = [];
    $chosen = null;
    foreach ($unique as $path) {
        if (!is_file($path)) {
            continue;
        }
        $row = keep41_probe($path);
        $examined[] = $row;
        if ($chosen === null && $row['reason'] === 'compatible') {
            $chosen = $row;
        }
    }

    return [
        'path_search_failed' => $pathSearchFailed,
        'path_search_diag' => $pathSearchDiag,
        'examined' => $examined,
        'chosen' => $chosen,
    ];
}

function keep41_path_search_lines(array $scan): string
{
    $text = '';
    $rows = $scan['path_search_diag'] ?? [];
    if (!is_array($rows)) {
        return '';
    }
    foreach ($rows as $diag) {
        if (!is_array($diag)) {
            continue;
        }
        $text .= 'path_search_command=' . keep41_one_line((string) ($diag['command'] ?? '')) . "\n";
        $text .= 'path_search_exit=' . (int) ($diag['exit_code'] ?? -1) . "\n";
        $text .= 'path_search_timed_out=' . (!empty($diag['timed_out']) ? 'yes' : 'no') . "\n";
        $text .= 'path_search_stdout=' . keep41_one_line((string) ($diag['stdout'] ?? '')) . "\n";
        $text .= 'path_search_stderr=' . keep41_one_line((string) ($diag['stderr'] ?? '')) . "\n";
    }

    return $text;
}

/**
 * @param array{path:string,exists:bool,version:string,abort:string,reason:string} $row
 */
function keep41_seen_line(array $row): string
{
    return 'seen=' . $row['path'] . ' version=' . $row['version'] . ' abort=' . $row['abort'] . ' reason=' . $row['reason'] . "\n";
}

/**
 * @param array{path_search_failed:bool,examined:list<array{path:string,exists:bool,version:string,abort:string,reason:string}>,chosen:?array{path:string,exists:bool,version:string,abort:string,reason:string}} $scan
 * @return array{exit:int,text:string}
 */
function keep41_build_report(array $scan, bool $procBlocked = false): array
{
    $text = "KEEP41_CLIENT_CHECK\nmode=check\ndatabase=not_contacted\nsql=not_run\n";
    if ($procBlocked) {
        $text .= "result=CHECK_UNAVAILABLE\nclient_path=\nclient_name=\nversion=\nabort_source_on_error=no\nreason=proc_open_unavailable\n";

        return ['exit' => 5, 'text' => $text];
    }
    $chosen = $scan['chosen'];
    if ($chosen !== null) {
        $text .= "result=COMPATIBLE\n";
        $text .= 'client_path=' . $chosen['path'] . "\n";
        $text .= 'client_name=' . basename($chosen['path']) . "\n";
        $text .= 'version=' . $chosen['version'] . "\n";
        $text .= "abort_source_on_error=yes\nreason=compatible\n";
        foreach ($scan['examined'] as $row) {
            if ($row['path'] === $chosen['path']) {
                continue;
            }
            $text .= keep41_seen_line($row);
        }
        if ($scan['path_search_failed']) {
            $text .= "path_search=failed\n";
            $text .= keep41_path_search_lines($scan);
        }

        return ['exit' => 0, 'text' => $text];
    }
    if ($scan['path_search_failed']) {
        $text .= "result=CHECK_UNAVAILABLE\nclient_path=\nclient_name=\nversion=\nabort_source_on_error=no\nreason=path_search_failed\n";
        $text .= keep41_path_search_lines($scan);
        foreach ($scan['examined'] as $row) {
            $text .= keep41_seen_line($row);
        }

        return ['exit' => 5, 'text' => $text];
    }
    if ($scan['examined'] === []) {
        $text .= "result=NONE\nclient_path=\nclient_name=\nversion=\nabort_source_on_error=no\nreason=no_client_file\n";

        return ['exit' => 3, 'text' => $text];
    }
    $onlyUnrun = true;
    foreach ($scan['examined'] as $row) {
        if ($row['reason'] !== 'run_failed' && $row['reason'] !== 'check_unavailable') {
            $onlyUnrun = false;
            break;
        }
    }
    if ($onlyUnrun) {
        $text .= "result=CHECK_UNAVAILABLE\nclient_path=\nclient_name=\nversion=\nabort_source_on_error=no\nreason=run_failed\n";
        foreach ($scan['examined'] as $row) {
            $text .= keep41_seen_line($row);
        }

        return ['exit' => 5, 'text' => $text];
    }
    $text = "KEEP41_CLIENT_CHECK\nmode=check\ndatabase=not_contacted\nsql=not_run\n";
    $text .= "result=NONE\nclient_path=\nclient_name=\nversion=\nabort_source_on_error=no\nreason=no_compatible_client\n";
    foreach ($scan['examined'] as $row) {
        $text .= keep41_seen_line($row);
    }

    return ['exit' => 3, 'text' => $text];
}

if (defined('KEEP41_CLIENT_CHECK_LIBRARY')) {
    return;
}

$args = array_slice($argv, 1);
if ($args !== [] && $args !== ['--check']) {
    echo "REFUSED\n";
    echo "This file only runs the client check. Deletion is not enabled.\n";
    exit(4);
}

if (keep41_proc_open_blocked()) {
    $report = keep41_build_report([
        'path_search_failed' => false,
        'examined' => [],
        'chosen' => null,
    ], true);
} else {
    $report = keep41_build_report(keep41_scan());
}
echo $report['text'];
exit($report['exit']);
