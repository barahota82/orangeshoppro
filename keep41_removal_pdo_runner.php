<?php
declare(strict_types=1);

/**
 * KEEP41 batch runner. PDO only. Does not launch a database client.
 * Default CLI does not delete. Does not bootstrap catalog schema.
 * Does not run the PDO backup export.
 * Accepted SQL is loaded from keep41_accepted next to this file.
 * Those carriers are PHP and do not print SQL for a web request.
 * Raw .sql inside the site root is refused. The review folder is not an implicit source.
 */

const KEEP41_PRECHECK_SHA256 = '72c0ce0cc006aaf5f8b81705e990af2eed6cb5780a8349fa1af0172babfcee90';
const KEEP41_POSTCHECK_SHA256 = 'c393869190d89c4701d142fef1a3840b3bf8e5bb075180e6d98114c316e9b486';
const KEEP41_SEQUENCE_SHA256 = '284205fdeeea0453bb7114c977765280cafd5f2d894bb77ff84702ab78d8b871';
const KEEP41_JOB_ID = 'keep41-removal';
const KEEP41_ACCEPTED_DIRNAME = 'keep41_accepted';

function keep41_runner_accepted_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . KEEP41_ACCEPTED_DIRNAME;
}

function keep41_resolve_accepted_path(string $dir, string $base): string
{
    $php = $dir . DIRECTORY_SEPARATOR . $base . '.php';
    if (is_file($php)) {
        return $php;
    }
    $sql = $dir . DIRECTORY_SEPARATOR . $base . '.sql';
    if (is_file($sql)) {
        return $sql;
    }

    throw new RuntimeException('ACCEPTED_SQL_HASH');
}

function keep41_read_accepted_bytes(string $path): string
{
    if (str_ends_with(strtolower($path), '.php')) {
        $payload = include $path;
        if (!is_string($payload)) {
            throw new RuntimeException('ACCEPTED_SQL_UNREADABLE');
        }

        return $payload;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RuntimeException('ACCEPTED_SQL_UNREADABLE');
    }

    return $raw;
}

function keep41_path_inside(string $parent, string $child): bool
{
    $parentNorm = strtolower(rtrim(str_replace('\\', '/', $parent), '/'));
    $childNorm = strtolower(rtrim(str_replace('\\', '/', $child), '/'));

    return $childNorm === $parentNorm || str_starts_with($childNorm, $parentNorm . '/');
}

function keep41_refused_text(): string
{
    return "KEEP41_PDO_RUNNER\nmode=refused\ndatabase=not_contacted\nsql=not_run\nsession_result=REFUSED\ndetail_rows=0\ntransaction=not_started\nlocks=not_held\nDeletion is not enabled.\n";
}

/**
 * @param array<string,mixed> $row
 */
function keep41_report(array $row): string
{
    $text = "KEEP41_PDO_RUNNER\n";
    $text .= 'mode=' . (string) ($row['mode'] ?? 'execute') . "\n";
    $text .= 'database=' . (string) ($row['database'] ?? '') . "\n";
    $text .= 'sql=' . (string) ($row['sql'] ?? 'not_run') . "\n";
    $text .= 'session_result=' . (string) ($row['session_result'] ?? '') . "\n";
    $text .= 'detail_rows=' . (string) ($row['detail_rows'] ?? '0') . "\n";
    $text .= 'transaction=' . (string) ($row['transaction'] ?? 'not_started') . "\n";
    $text .= 'locks=' . (string) ($row['locks'] ?? 'not_held') . "\n";
    if (isset($row['lock_name'])) {
        $text .= 'lock_name=' . (string) $row['lock_name'] . "\n";
    }
    if (isset($row['lock_release']) && (string) $row['lock_release'] !== '') {
        $text .= 'lock_release=' . (string) $row['lock_release'] . "\n";
    }
    if (isset($row['read_after_unknown'])) {
        $text .= 'read_after_unknown=' . (string) $row['read_after_unknown'] . "\n";
        $text .= 'read_database=' . (string) ($row['read_database'] ?? '') . "\n";
        $text .= 'read_reference_count=' . (string) ($row['read_reference_count'] ?? '') . "\n";
        $text .= 'read_remove_present=' . (string) ($row['read_remove_present'] ?? '') . "\n";
    }
    if (isset($row['differences']) && is_array($row['differences'])) {
        foreach ($row['differences'] as $difference) {
            if (!is_array($difference)) {
                continue;
            }
            $text .= 'check_id=' . (string) ($difference['check_id'] ?? '') . "\n";
            $text .= 'found=' . (string) ($difference['found'] ?? '') . "\n";
        }
    }
    if (isset($row['message']) && (string) $row['message'] !== '') {
        $text .= 'message=' . (string) $row['message'] . "\n";
    }

    return $text;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function keep41_finish(array $row): array
{
    $row['text'] = keep41_report($row);

    return $row;
}

function keep41_file_sha256(string $path): string
{
    $hash = hash_file('sha256', $path);

    return is_string($hash) ? $hash : '';
}

function keep41_once(string $haystack, string $needle): int
{
    $at = strpos($haystack, $needle);
    if ($at === false || strpos($haystack, $needle, $at + strlen($needle)) !== false) {
        throw new RuntimeException('ACCEPTED_SQL_MARKER');
    }

    return $at;
}

function keep41_strip_sql_comments(string $raw): string
{
    $lines = preg_split('/\R/', $raw);
    if ($lines === false) {
        throw new RuntimeException('ACCEPTED_SQL_UNREADABLE');
    }
    $kept = [];
    foreach ($lines as $line) {
        if (str_starts_with(ltrim($line), '--')) {
            continue;
        }
        $kept[] = $line;
    }

    return rtrim(implode("\n", $kept), " \t\r\n;");
}

/**
 * @return array{pre:string,post:string,delete:string,engine:string,temporary:string,for_update:list<string>,remove_in:string}
 */
function keep41_load_accepted_sql(string $dir, string $siteRoot): array
{
    if ($dir === '' || $siteRoot === '') {
        throw new RuntimeException('ACCEPTED_SQL_DIR');
    }
    $siteReal = realpath($siteRoot);
    $dirReal = realpath($dir);
    if ($siteReal === false) {
        throw new RuntimeException('SITE_ROOT_INVALID');
    }
    if ($dirReal === false) {
        throw new RuntimeException('ACCEPTED_SQL_HASH');
    }
    if (keep41_path_inside($siteReal, $dirReal)) {
        foreach (['PRECHECK.sql', 'POSTCHECK.sql', 'REMOVAL_SEQUENCE.sql'] as $rawName) {
            if (is_file($dirReal . DIRECTORY_SEPARATOR . $rawName)) {
                throw new RuntimeException('ACCEPTED_SQL_INSIDE_WEB_ROOT');
            }
        }
    }
    $prePath = keep41_resolve_accepted_path($dirReal, 'PRECHECK');
    $postPath = keep41_resolve_accepted_path($dirReal, 'POSTCHECK');
    $sequencePath = keep41_resolve_accepted_path($dirReal, 'REMOVAL_SEQUENCE');
    foreach ([$prePath, $postPath, $sequencePath] as $path) {
        if (str_ends_with(strtolower($path), '.sql') && keep41_path_inside($siteReal, $dirReal)) {
            throw new RuntimeException('ACCEPTED_SQL_INSIDE_WEB_ROOT');
        }
    }
    $expected = [
        $prePath => KEEP41_PRECHECK_SHA256,
        $postPath => KEEP41_POSTCHECK_SHA256,
        $sequencePath => KEEP41_SEQUENCE_SHA256,
    ];
    $payloads = [];
    foreach ($expected as $path => $sha) {
        $bytes = keep41_read_accepted_bytes($path);
        if (hash('sha256', $bytes) !== $sha) {
            throw new RuntimeException('ACCEPTED_SQL_HASH');
        }
        $payloads[$path] = $bytes;
    }
    $sequence = $payloads[$sequencePath];
    $pre = keep41_strip_sql_comments($payloads[$prePath]);
    $post = keep41_strip_sql_comments($payloads[$postPath]);
    if ($pre === '' || $post === '' || keep41_once($sequence, $pre) < 0 || keep41_once($sequence, $post) < 0) {
        throw new RuntimeException('ACCEPTED_SQL_MARKER');
    }
    $deleteAt = keep41_once($sequence, 'DELETE FROM `orangetest`.`orange_language_reference`');
    $deleteEnd = strpos($sequence, ';', $deleteAt);
    if ($deleteEnd === false) {
        throw new RuntimeException('ACCEPTED_SQL_MARKER');
    }
    $delete = substr($sequence, $deleteAt, $deleteEnd - $deleteAt);
    if (!preg_match('/IN\s*(\([^;]+\))/', $delete, $inMatch)) {
        throw new RuntimeException('ACCEPTED_SQL_MARKER');
    }
    $removeIn = $inMatch[1];
    if (substr_count($pre, $removeIn) !== 1 || substr_count($post, $removeIn) !== 1) {
        throw new RuntimeException('ACCEPTED_SQL_MARKER');
    }
    $engineAt = keep41_once($sequence, 'SELECT COUNT(*) INTO v_innodb');
    $engineEnd = strpos($sequence, ';', $engineAt);
    if ($engineEnd === false) {
        throw new RuntimeException('ACCEPTED_SQL_MARKER');
    }
    $engineOriginal = substr($sequence, $engineAt, $engineEnd - $engineAt);
    $engine = 'SELECT COUNT(*) AS innodb_count' . substr($engineOriginal, strlen('SELECT COUNT(*) INTO v_innodb'));
    $tempAt = keep41_once($sequence, 'CREATE TEMPORARY TABLE keep41_check');
    $tempEnd = strpos($sequence, ';', $tempAt);
    if ($tempEnd === false) {
        throw new RuntimeException('ACCEPTED_SQL_MARKER');
    }
    $temporary = substr($sequence, $tempAt, $tempEnd - $tempAt);
    $forUpdate = [];
    foreach ([
        'SELECT id FROM `orangetest`.`orange_language_reference` FOR UPDATE',
        'SELECT country_id FROM `orangetest`.`orange_country_locale_role` FOR UPDATE',
        'SELECT id FROM `orangetest`.`orange_brand_identity_translations` FOR UPDATE',
        'SELECT id FROM `orangetest`.`orange_content_locale_text` FOR UPDATE',
    ] as $statement) {
        keep41_once($sequence, $statement);
        $forUpdate[] = $statement;
    }
    keep41_once($sequence, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

    return [
        'pre' => $pre,
        'post' => $post,
        'delete' => $delete,
        'engine' => $engine,
        'temporary' => $temporary,
        'for_update' => $forUpdate,
        'remove_in' => $removeIn,
    ];
}

function keep41_load_lock_libraries(string $siteRoot): void
{
    if (function_exists('orange_backup_acquire_lock')
        && function_exists('orange_restore_acquire_lock')
        && function_exists('orange_restore_exec_acquire_lock')) {
        return;
    }
    $backup = $siteRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_runner.php';
    $restore = $siteRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
    $exec = $siteRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_execution_orchestrator.php';
    if (!is_file($backup) || !is_file($restore) || !is_file($exec)) {
        throw new RuntimeException('LOCK_LIBRARY_MISSING');
    }
    require_once $backup;
    require_once $restore;
    require_once $exec;
}

/**
 * Same DSN, attributes, time zone, and names as db() in config.php.
 * Does not execute config.php, so it does not start a session.
 * Does not load catalog schema by itself.
 * Pass $env when the caller already loaded .env.php. A second require would not return the array.
 *
 * @param array<string,mixed>|null $env
 * @return array{pdo:PDO,name:string}
 */
function keep41_open_site_pdo(string $siteRoot, ?array $env = null): array
{
    $configPath = $siteRoot . DIRECTORY_SEPARATOR . 'config.php';
    $envPath = $siteRoot . DIRECTORY_SEPARATOR . '.env.php';
    $config = is_file($configPath) ? file_get_contents($configPath) : false;
    if (!is_string($config)
        || !preg_match("/^const DB_HOST = '([^']+)';/m", $config, $host)
        || !preg_match("/^const DB_NAME = '([^']+)';/m", $config, $name)) {
        throw new RuntimeException('SITE_DB_CONSTANTS');
    }
    if ($env === null) {
        if (!is_file($envPath)) {
            throw new RuntimeException('SITE_ENV_MISSING');
        }
        $loaded = require $envPath;
        if (!is_array($loaded)) {
            throw new RuntimeException('SITE_ENV_MISSING');
        }
        $env = $loaded;
    }
    $user = trim((string) ($env['DB_USER'] ?? ''));
    $pass = (string) ($env['DB_PASS'] ?? '');
    if ($user === '') {
        throw new RuntimeException('SITE_DB_USER');
    }
    $dsn = 'mysql:host=' . $host[1] . ';dbname=' . $name[1] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    try {
        $pdo->exec("SET time_zone = '+03:00'");
        $pdo->exec('SET NAMES utf8mb4');
    } catch (Throwable) {
    }

    return ['pdo' => $pdo, 'name' => $name[1]];
}

function keep41_connection_lost(Throwable $e): bool
{
    $code = 0;
    if ($e instanceof PDOException) {
        $info = $e->errorInfo ?? null;
        $code = is_array($info) ? (int) ($info[1] ?? 0) : 0;
    }
    $message = strtolower($e->getMessage());

    return in_array($code, [2006, 2013, 1053, 1927], true)
        || str_contains($message, 'gone away')
        || str_contains($message, 'lost connection')
        || str_contains($message, 'server shutdown');
}

function keep41_safe_message(Throwable $e): string
{
    $message = preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage();
    $message = preg_replace('/\b(password|passwd|pwd|secret|token)\b\s*[=:]\s*\S+/i', '$1=[redacted]', $message) ?? $message;

    return substr($message, 0, 180);
}

function keep41_cli_hooks_refused(array $options): bool
{
    if (empty($options['cli'])) {
        return false;
    }
    if (!empty($options['fail_after_delete']) || !empty($options['fail_rollback']) || !empty($options['fail_lock_release'])) {
        return true;
    }
    if (isset($options['before_commit']) || isset($options['sql_override']) || isset($options['before_lock_release'])) {
        return true;
    }

    return isset($options['throw_after_lock']) && (string) $options['throw_after_lock'] !== '';
}

/**
 * Ownership is checked before any library release, and again after a release
 * that was allowed to run. A library release may return without an exception
 * while the lock file remains. released is recorded only after an owned file
 * is gone. Another process, or ownership that cannot be settled, is not passed
 * to a function that can delete the file. This process still drops its own
 * backup handle without deleting that file.
 *
 * @param array{backup:bool,restore:bool,exec:bool} $held
 * @param callable|null $before
 */
function keep41_release_locks(string $backupRoot, string $workRoot, array &$held, bool $failRelease = false, ?callable $before = null): ?string
{
    if (empty($held['backup']) && empty($held['restore']) && empty($held['exec'])) {
        return null;
    }
    $errors = [];
    $paths = [
        'backup' => orange_backup_lock_path($backupRoot),
        'restore' => orange_restore_global_lock_path($workRoot),
        'exec' => orange_restore_exec_lock_path($workRoot),
    ];
    if ($before !== null) {
        $before($paths);
    }
    foreach (['exec', 'restore', 'backup'] as $name) {
        if (empty($held[$name])) {
            continue;
        }
        $path = $paths[$name];
        try {
            if ($failRelease) {
                throw new RuntimeException('INJECTED_LOCK_RELEASE');
            }
            $owner = keep41_lock_owner_before_release($name, $path);
            if ($owner !== 'self') {
                if ($name === 'backup') {
                    keep41_release_own_backup_handle();
                }
                if ($owner === 'absent' && !is_file($path)) {
                    $held[$name] = false;
                    continue;
                }
                if (!is_file($path)) {
                    $errors[] = $name . ':LOCK_OWNERSHIP_UNSETTLED';
                    continue;
                }
                $after = keep41_lock_file_owner($path);
                if ($after === 'other') {
                    $errors[] = $name . ':LOCK_OWNED_BY_OTHER';
                } else {
                    $errors[] = $name . ':LOCK_OWNERSHIP_UNSETTLED';
                }
                continue;
            }
            if ($name === 'exec') {
                orange_restore_exec_release_lock($workRoot, KEEP41_JOB_ID);
            } elseif ($name === 'restore') {
                orange_restore_release_lock($workRoot);
            } else {
                orange_backup_release_lock();
            }
        } catch (Throwable $e) {
            $errors[] = $name . ':' . keep41_safe_message($e);
        }
        if (!is_file($path)) {
            $held[$name] = false;
            continue;
        }
        $after = keep41_lock_file_owner($path);
        if ($after === 'other') {
            $errors[] = $name . ':LOCK_OWNED_BY_OTHER';
            continue;
        }
        if ($after === 'unknown') {
            $errors[] = $name . ':LOCK_OWNERSHIP_UNSETTLED';
            continue;
        }
        $errors[] = $name . ':LOCK_STILL_PRESENT';
    }

    return $errors === [] ? null : implode(' ', $errors);
}

/**
 * Closes the backup handle held by this process. Does not delete the lock file.
 */
function keep41_release_own_backup_handle(): void
{
    global $orangeBackupLockHandle;

    if (!is_resource($orangeBackupLockHandle)) {
        return;
    }
    flock($orangeBackupLockHandle, LOCK_UN);
    fclose($orangeBackupLockHandle);
    $orangeBackupLockHandle = null;
}

function keep41_lock_file_owner(string $path): string
{
    $raw = @file_get_contents($path);

    return keep41_lock_owner_of_bytes(is_string($raw) ? $raw : false);
}

/**
 * The backup lock is held open by this process. A second open can fail while
 * that handle exists, and the handle can also show a different payload if the
 * file was replaced. Disagreeing views are unsettled and must not be deleted.
 */
function keep41_lock_owner_before_release(string $name, string $path): string
{
    if (!is_file($path)) {
        return 'absent';
    }
    $disk = @file_get_contents($path);
    $diskText = is_string($disk) ? $disk : '';
    if ($name !== 'backup') {
        return keep41_lock_owner_of_bytes($diskText);
    }
    $handleText = keep41_backup_handle_bytes();
    $diskSettled = trim($diskText) !== '';
    $handleSettled = trim($handleText) !== '';
    if ($diskSettled && $handleSettled && trim($diskText) !== trim($handleText)) {
        return 'unknown';
    }
    if ($diskSettled) {
        return keep41_lock_owner_of_bytes($diskText);
    }
    if ($handleSettled) {
        return keep41_lock_owner_of_bytes($handleText);
    }

    return 'unknown';
}

function keep41_backup_handle_bytes(): string
{
    global $orangeBackupLockHandle;

    if (!is_resource($orangeBackupLockHandle)) {
        return '';
    }
    $position = ftell($orangeBackupLockHandle);
    rewind($orangeBackupLockHandle);
    $raw = stream_get_contents($orangeBackupLockHandle);
    if ($position !== false) {
        fseek($orangeBackupLockHandle, $position);
    }

    return is_string($raw) ? $raw : '';
}

function keep41_lock_owner_of_bytes(string|false $raw): string
{
    if (!is_string($raw) || trim($raw) === '') {
        return 'unknown';
    }
    $meta = json_decode($raw, true);
    if (!is_array($meta) || !array_key_exists('pid', $meta)) {
        return 'unknown';
    }
    $pid = (int) $meta['pid'];
    if ($pid <= 0) {
        return 'unknown';
    }
    if ($pid !== getmypid()) {
        return 'other';
    }
    if (array_key_exists('job_id', $meta) && (string) $meta['job_id'] !== KEEP41_JOB_ID) {
        return 'other';
    }

    return 'self';
}

/**
 * Ownership is written into $held immediately after each successful acquire.
 *
 * @param array{backup:bool,restore:bool,exec:bool} $held
 * @return array{ok:bool,name:string,message:string}
 */
function keep41_acquire_locks(string $backupRoot, string $workRoot, array &$held, string $throwAfter): array
{
    $held = ['backup' => false, 'restore' => false, 'exec' => false];
    $backup = orange_backup_acquire_lock($backupRoot);
    if (empty($backup['acquired'])) {
        return ['ok' => false, 'name' => 'backup', 'message' => (string) ($backup['reason'] ?? 'backup_lock')];
    }
    $held['backup'] = true;
    if ($throwAfter === 'backup') {
        throw new RuntimeException('INJECTED_AFTER_LOCK_BACKUP');
    }
    $restore = orange_restore_acquire_lock($workRoot, KEEP41_JOB_ID);
    if (empty($restore['ok'])) {
        return ['ok' => false, 'name' => 'restore', 'message' => (string) ($restore['message'] ?? 'restore_lock')];
    }
    $held['restore'] = true;
    if ($throwAfter === 'restore') {
        throw new RuntimeException('INJECTED_AFTER_LOCK_RESTORE');
    }
    $exec = orange_restore_exec_acquire_lock($workRoot, KEEP41_JOB_ID);
    if (empty($exec['ok'])) {
        return ['ok' => false, 'name' => 'exec', 'message' => (string) ($exec['message'] ?? 'exec_lock')];
    }
    $held['exec'] = true;

    return ['ok' => true, 'name' => '', 'message' => ''];
}

function keep41_fetch_all(PDO $pdo, string $sql): void
{
    $statement = $pdo->query($sql);
    if ($statement instanceof PDOStatement) {
        $statement->fetchAll();
        $statement->closeCursor();
    }
}

/**
 * @return list<array{check_id:string,found:string}>
 */
function keep41_read_differences(PDO $pdo): array
{
    $statement = $pdo->query('SELECT check_id, found FROM keep41_check');
    $rows = $statement instanceof PDOStatement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'check_id' => str_replace(["\r", "\n"], ' ', (string) ($row['check_id'] ?? '')),
            'found' => str_replace(["\r", "\n"], ' ', (string) ($row['found'] ?? '')),
        ];
    }

    return $out;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function keep41_attach_release(array $row, ?string $releaseError, bool $ownedAny): array
{
    if ($releaseError !== null) {
        $row['locks'] = 'release_failed';
        $row['lock_release'] = $releaseError;

        return $row;
    }
    if ($ownedAny) {
        $row['locks'] = 'released';
    } elseif (!isset($row['locks']) || (string) $row['locks'] === '') {
        $row['locks'] = 'not_held';
    }

    return $row;
}

/**
 * Counts choose which accepted check to run. They do not settle the outcome.
 * This read does not delete and does not start another removal.
 *
 * @param array<string,mixed> $sql
 * @param array<string,mixed> $options
 * @return array{state:string,database:string,reference_count:string,remove_present:string,differences:list<array{check_id:string,found:string}>}
 */
function keep41_settle_unknown(array $sql, array $options): array
{
    $empty = [
        'state' => 'unsettled',
        'database' => '',
        'reference_count' => '',
        'remove_present' => '',
        'differences' => [],
    ];
    if (!isset($options['reconnect']) || !is_callable($options['reconnect'])) {
        return $empty;
    }
    try {
        $pdo = $options['reconnect']();
        if (!$pdo instanceof PDO) {
            return $empty;
        }
        $database = (string) $pdo->query("SELECT IFNULL(DATABASE(), 'NULL')")->fetchColumn();
        if ($database !== 'orangetest') {
            $empty['database'] = $database;

            return $empty;
        }
        $removeIn = (string) ($sql['remove_in'] ?? '');
        if ($removeIn === '') {
            $empty['database'] = $database;

            return $empty;
        }
        $reference = (int) $pdo->query('SELECT COUNT(*) FROM `orangetest`.`orange_language_reference`')->fetchColumn();
        $present = (int) $pdo->query('SELECT COUNT(*) FROM `orangetest`.`orange_language_reference` WHERE locale_code IN ' . $removeIn)->fetchColumn();
        $result = [
            'state' => 'unsettled',
            'database' => $database,
            'reference_count' => (string) $reference,
            'remove_present' => (string) $present,
            'differences' => [],
        ];
        $checkSql = '';
        $settled = '';
        if ($reference === 104 && $present === 63) {
            $checkSql = (string) ($sql['pre'] ?? '');
            $settled = 'settled_precheck';
        } elseif ($reference === 41 && $present === 0) {
            $checkSql = (string) ($sql['post'] ?? '');
            $settled = 'settled_postcheck';
        }
        if ($checkSql === '' || $settled === '' || (string) ($sql['temporary'] ?? '') === '') {
            return $result;
        }
        $pdo->exec('DROP TEMPORARY TABLE IF EXISTS keep41_check');
        $pdo->exec((string) $sql['temporary']);
        $pdo->exec('INSERT INTO keep41_check ' . $checkSql);
        $differences = keep41_read_differences($pdo);
        if ($differences === []) {
            $result['state'] = $settled;

            return $result;
        }
        $result['differences'] = $differences;

        return $result;
    } catch (Throwable) {
        return $empty;
    }
}

/**
 * @param array<string,mixed> $read
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function keep41_merge_read(array $row, array $read): array
{
    $row['read_after_unknown'] = (string) ($read['state'] ?? 'unsettled');
    $row['read_database'] = (string) ($read['database'] ?? '');
    $row['read_reference_count'] = (string) ($read['reference_count'] ?? '');
    $row['read_remove_present'] = (string) ($read['remove_present'] ?? '');
    if (isset($read['differences']) && is_array($read['differences']) && $read['differences'] !== []) {
        $row['differences'] = $read['differences'];
    }

    return $row;
}

/**
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function keep41_removal_execute(PDO $pdo, array $options): array
{
    $cli = !empty($options['cli']);
    if (keep41_cli_hooks_refused($options)) {
        return keep41_finish([
            'exit' => 4,
            'mode' => 'refused',
            'database' => 'not_contacted',
            'sql' => 'not_run',
            'session_result' => 'REFUSED',
            'transaction' => 'not_started',
            'locks' => 'not_held',
            'message' => 'Deletion is not enabled.',
        ]);
    }
    $backupRoot = (string) ($options['backup_root'] ?? '');
    $workRoot = (string) ($options['work_root'] ?? '');
    $siteRoot = (string) ($options['site_root'] ?? '');
    if ($backupRoot === '' || $workRoot === '' || $siteRoot === '') {
        return keep41_finish([
            'exit' => 5,
            'session_result' => 'STOPPED_ERROR',
            'database' => '',
            'sql' => 'not_run',
            'transaction' => 'not_started',
            'locks' => 'not_held',
            'message' => 'LOCK_ROOTS_REQUIRED',
        ]);
    }
    $held = ['backup' => false, 'restore' => false, 'exec' => false];
    $transactionState = 'not_started';
    $sqlState = 'not_run';
    $database = '';
    $dataResult = null;
    $sql = [
        'pre' => '',
        'post' => '',
        'delete' => '',
        'engine' => '',
        'temporary' => '',
        'for_update' => [],
        'remove_in' => '',
    ];
    try {
        if (isset($options['sql_override'])) {
            if (!is_array($options['sql_override'])) {
                throw new RuntimeException('SQL_OVERRIDE_INVALID');
            }
            $sql = $options['sql_override'];
        } else {
            $sql = keep41_load_accepted_sql((string) ($options['accepted_dir'] ?? ''), $siteRoot);
        }
        $gate = $pdo->query('SELECT IFNULL(DATABASE(), \'NULL\') AS db_name, @@in_transaction AS in_transaction');
        $gateRow = $gate instanceof PDOStatement ? $gate->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($gateRow)) {
            throw new RuntimeException('GATE_UNREADABLE');
        }
        $database = (string) $gateRow['db_name'];
        if ($database !== 'orangetest') {
            $dataResult = [
                'exit' => 3,
                'database' => $database,
                'sql' => 'not_run',
                'session_result' => 'REJECTED_DATABASE',
                'detail_rows' => $database,
                'transaction' => 'not_started',
                'locks' => 'not_held',
            ];
        } elseif ((int) $gateRow['in_transaction'] !== 0) {
            $dataResult = [
                'exit' => 3,
                'database' => $database,
                'sql' => 'not_run',
                'session_result' => 'REJECTED_OPEN_TRANSACTION',
                'detail_rows' => (string) $gateRow['in_transaction'],
                'transaction' => 'left_open',
                'locks' => 'not_held',
            ];
        } else {
            $pdo->exec('SET NAMES utf8mb4');
            $engineStatement = $pdo->query((string) $sql['engine']);
            $engineRow = $engineStatement instanceof PDOStatement ? $engineStatement->fetch(PDO::FETCH_ASSOC) : false;
            $innodb = is_array($engineRow) ? (int) ($engineRow['innodb_count'] ?? 0) : 0;
            if ($innodb !== 4) {
                $dataResult = [
                    'exit' => 3,
                    'database' => $database,
                    'sql' => 'not_run',
                    'session_result' => 'REJECTED_ENGINE',
                    'detail_rows' => (string) $innodb,
                    'transaction' => 'not_started',
                    'locks' => 'not_held',
                ];
            } else {
                keep41_load_lock_libraries($siteRoot);
                $locks = keep41_acquire_locks($backupRoot, $workRoot, $held, (string) ($options['throw_after_lock'] ?? ''));
                if (!$locks['ok']) {
                    $dataResult = [
                        'exit' => 5,
                        'database' => $database,
                        'sql' => 'not_run',
                        'session_result' => 'LOCK_NOT_ACQUIRED',
                        'detail_rows' => '0',
                        'transaction' => 'not_started',
                        'locks' => 'not_held',
                        'lock_name' => $locks['name'],
                        'message' => preg_replace('/\s+/', ' ', $locks['message']) ?? $locks['message'],
                    ];
                } else {
                    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS keep41_check');
                    $pdo->exec((string) $sql['temporary']);
                    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                    if ($pdo->beginTransaction() !== true) {
                        throw new RuntimeException('TRANSACTION_NOT_STARTED');
                    }
                    $transactionState = 'open';
                    foreach ($sql['for_update'] as $statement) {
                        keep41_fetch_all($pdo, (string) $statement);
                    }
                    $pdo->exec('DELETE FROM keep41_check');
                    $pdo->exec('INSERT INTO keep41_check ' . (string) $sql['pre']);
                    $differences = keep41_read_differences($pdo);
                    if ($differences !== []) {
                        $pdo->rollBack();
                        $transactionState = 'rolled_back';
                        $sqlState = 'rolled_back';
                        $dataResult = [
                            'exit' => 3,
                            'database' => $database,
                            'sql' => 'rolled_back',
                            'session_result' => 'DIFFERENCE_NEEDS_STOP_DECISION',
                            'detail_rows' => (string) count($differences),
                            'transaction' => 'rolled_back',
                            'differences' => $differences,
                        ];
                    } else {
                        $deleted = $pdo->exec((string) $sql['delete']);
                        if ($deleted !== 63) {
                            $pdo->rollBack();
                            $transactionState = 'rolled_back';
                            $sqlState = 'rolled_back';
                            $dataResult = [
                                'exit' => 3,
                                'database' => $database,
                                'sql' => 'rolled_back',
                                'session_result' => 'ROLLED_BACK_ROW_COUNT',
                                'detail_rows' => (string) $deleted,
                                'transaction' => 'rolled_back',
                            ];
                        } elseif (!empty($options['fail_after_delete'])) {
                            throw new RuntimeException('INJECTED_AFTER_DELETE');
                        } else {
                            $pdo->exec('DELETE FROM keep41_check');
                            $pdo->exec('INSERT INTO keep41_check ' . (string) $sql['post']);
                            $postDifferences = keep41_read_differences($pdo);
                            if ($postDifferences !== []) {
                                $pdo->rollBack();
                                $transactionState = 'rolled_back';
                                $sqlState = 'rolled_back';
                                $dataResult = [
                                    'exit' => 3,
                                    'database' => $database,
                                    'sql' => 'rolled_back',
                                    'session_result' => 'ROLLED_BACK_POSTCHECK',
                                    'detail_rows' => (string) count($postDifferences),
                                    'transaction' => 'rolled_back',
                                    'differences' => $postDifferences,
                                ];
                            } else {
                                try {
                                    if (isset($options['before_commit']) && is_callable($options['before_commit'])) {
                                        $connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
                                        $options['before_commit']($pdo, $connectionId);
                                    }
                                    if ($pdo->commit() !== true) {
                                        throw new RuntimeException('COMMIT_RETURNED_FALSE');
                                    }
                                    $transactionState = 'committed';
                                    $sqlState = 'committed';
                                    $dataResult = [
                                        'exit' => 0,
                                        'database' => $database,
                                        'sql' => 'committed',
                                        'session_result' => 'COMMITTED',
                                        'detail_rows' => '63',
                                        'transaction' => 'committed',
                                    ];
                                } catch (Throwable $commitError) {
                                    $transactionState = 'unknown';
                                    $sqlState = 'unknown';
                                    $dataResult = keep41_merge_read([
                                        'exit' => 5,
                                        'database' => $database,
                                        'sql' => 'unknown',
                                        'session_result' => 'COMMIT_OUTCOME_UNKNOWN',
                                        'detail_rows' => '63',
                                        'transaction' => 'unknown',
                                        'message' => keep41_connection_lost($commitError) ? 'connection_lost_around_commit' : keep41_safe_message($commitError),
                                    ], keep41_settle_unknown($sql, $options));
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        if ($dataResult === null && $transactionState === 'open') {
            $lost = keep41_connection_lost($e);
            try {
                if (!empty($options['fail_rollback'])) {
                    throw new RuntimeException('INJECTED_ROLLBACK_FAILED');
                }
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $transactionState = 'rolled_back';
                $sqlState = 'rolled_back';
                $dataResult = [
                    'exit' => 3,
                    'database' => $database !== '' ? $database : 'orangetest',
                    'sql' => 'rolled_back',
                    'session_result' => 'ROLLED_BACK_ERROR',
                    'detail_rows' => '0',
                    'transaction' => 'rolled_back',
                    'message' => keep41_safe_message($e),
                ];
            } catch (Throwable $rollbackError) {
                if ($lost || keep41_connection_lost($rollbackError)) {
                    $transactionState = 'unknown';
                    $sqlState = 'unknown';
                    $dataResult = keep41_merge_read([
                        'exit' => 5,
                        'database' => $database !== '' ? $database : 'orangetest',
                        'sql' => 'unknown',
                        'session_result' => 'COMMIT_OUTCOME_UNKNOWN',
                        'detail_rows' => '0',
                        'transaction' => 'unknown',
                        'message' => 'connection_lost_before_commit_confirmed',
                    ], keep41_settle_unknown($sql, $options));
                } else {
                    $transactionState = 'rollback_failed';
                    $sqlState = 'rollback_failed';
                    $dataResult = [
                        'exit' => 5,
                        'database' => $database !== '' ? $database : 'orangetest',
                        'sql' => 'rollback_failed',
                        'session_result' => 'ROLLBACK_FAILED',
                        'detail_rows' => '0',
                        'transaction' => 'rollback_failed',
                        'message' => keep41_safe_message($e) . ' rollback:' . keep41_safe_message($rollbackError),
                    ];
                }
            }
        } elseif ($dataResult === null) {
            $dataResult = [
                'exit' => 5,
                'database' => $database,
                'sql' => $sqlState,
                'session_result' => 'STOPPED_ERROR',
                'detail_rows' => '0',
                'transaction' => $transactionState,
                'message' => keep41_safe_message($e),
            ];
        }
    }
    $ownedAny = !empty($held['backup']) || !empty($held['restore']) || !empty($held['exec']);
    $beforeRelease = null;
    if (isset($options['before_lock_release']) && is_callable($options['before_lock_release'])) {
        $beforeRelease = $options['before_lock_release'];
    }
    $releaseError = null;
    try {
        $releaseError = keep41_release_locks(
            $backupRoot,
            $workRoot,
            $held,
            !$cli && !empty($options['fail_lock_release']),
            $beforeRelease
        );
    } catch (Throwable $releaseException) {
        $releaseError = 'release:' . keep41_safe_message($releaseException);
    }
    if ($dataResult === null) {
        $dataResult = [
            'exit' => 5,
            'database' => $database,
            'sql' => $sqlState,
            'session_result' => 'STOPPED_ERROR',
            'detail_rows' => '0',
            'transaction' => $transactionState,
            'message' => 'RESULT_MISSING',
        ];
    }
    if ($releaseError !== null && (int) ($dataResult['exit'] ?? 0) === 0) {
        $dataResult['exit'] = 5;
    }

    return keep41_finish(keep41_attach_release($dataResult, $releaseError, $ownedAny));
}

function keep41_removal_cli(array $argv): void
{
    $args = array_slice($argv, 1);
    $execute = false;
    $siteRoot = '';
    foreach ($args as $arg) {
        if ($arg === '--execute-keep41-removal') {
            $execute = true;
            continue;
        }
        if (str_starts_with($arg, '--site-root=')) {
            $siteRoot = substr($arg, strlen('--site-root='));
            continue;
        }
        echo keep41_refused_text();
        exit(4);
    }
    if (!$execute) {
        echo keep41_refused_text();
        exit(4);
    }
    if ($siteRoot === '') {
        $siteRoot = __DIR__;
    }
    $siteRoot = realpath($siteRoot) ?: '';
    if ($siteRoot === '' || !is_file($siteRoot . DIRECTORY_SEPARATOR . 'config.php')) {
        fwrite(STDOUT, keep41_report([
            'mode' => 'execute',
            'database' => 'not_contacted',
            'sql' => 'not_run',
            'session_result' => 'STOPPED_ERROR',
            'transaction' => 'not_started',
            'locks' => 'not_held',
            'message' => 'SITE_ROOT_INVALID',
        ]));
        exit(5);
    }
    try {
        keep41_load_lock_libraries($siteRoot);
        $env = orange_backup_load_env_array($siteRoot);
        $backupRoot = orange_backup_resolve_root($env);
        $workRoot = orange_restore_resolve_work_root($env);
        $opened = keep41_open_site_pdo($siteRoot, $env);
        $result = keep41_removal_execute($opened['pdo'], [
            'cli' => true,
            'site_root' => $siteRoot,
            'backup_root' => $backupRoot,
            'work_root' => $workRoot,
            'accepted_dir' => keep41_runner_accepted_dir(),
            'reconnect' => static function () use ($siteRoot, $env): PDO {
                return keep41_open_site_pdo($siteRoot, $env)['pdo'];
            },
        ]);
    } catch (Throwable $e) {
        fwrite(STDOUT, keep41_report([
            'mode' => 'execute',
            'database' => 'not_contacted',
            'sql' => 'not_run',
            'session_result' => 'STOPPED_ERROR',
            'transaction' => 'not_started',
            'locks' => 'not_held',
            'message' => keep41_safe_message($e),
        ]));
        exit(5);
    }
    fwrite(STDOUT, $result['text']);
    exit((int) $result['exit']);
}

if (PHP_SAPI !== 'cli') {
    echo "CLI_ONLY\n";
    exit(2);
}

if (!defined('KEEP41_REMOVAL_LIBRARY')) {
    keep41_removal_cli($argv);
}
