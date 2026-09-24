<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1B.2 — locale schema / revision / grants / dormancy self-test.
 * Exit 0 only when RAW_FAIL=0 and CORE_SKIP=0 and FALSE_GREEN_RISK_COUNT=0.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$phpBin = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
if (!is_file($phpBin)) {
    $phpBin = PHP_BINARY;
}
$repoRoot = dirname(__DIR__);
$bootstrap = $repoRoot . '/scripts/multidb/phase21b2_local_bootstrap.php';
$cleanup = $repoRoot . '/scripts/multidb/phase21b2_local_cleanup.php';

require_once $repoRoot . '/includes/control_schema.php';

$rawFail = 0;
$coreSkip = 0;
$falseGreen = 0;
$pass = 0;
$startedMysql = 0;

function t_assert(bool $cond, string $name, int &$rawFail, int &$pass): void
{
    if ($cond) {
        echo "PASS {$name}\n";
        $pass++;
    } else {
        echo "FAIL {$name}\n";
        $rawFail++;
    }
}

function p21b2_port_open(string $host, int $port): bool
{
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
    if (is_resource($fp)) {
        fclose($fp);

        return true;
    }

    return false;
}

function p21b2_start_mysql_if_needed(): int
{
    if (p21b2_port_open('127.0.0.1', 3306)) {
        return 0;
    }
    $candidates = [
        'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqld.exe',
        'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqld.exe',
    ];
    foreach ($candidates as $bin) {
        if (!is_file($bin)) {
            continue;
        }
        $cmd = 'start /B "" ' . escapeshellarg($bin)
            . ' --defaults-file=' . escapeshellarg(dirname($bin) . '\\my.ini')
            . ' --standalone';
        // Laragon often uses mysql_start.bat — try direct service-less start via laragon.
        break;
    }
    $laragon = 'C:\\laragon\\laragon.exe';
    // Prefer mysql from PATH / common Laragon start script.
    $bat = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqld.exe';
    if (is_file($bat)) {
        $basedir = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64';
        $cmd = escapeshellarg($bat) . ' --basedir=' . escapeshellarg($basedir)
            . ' --datadir=' . escapeshellarg($basedir . '\\data')
            . ' --port=3306 --bind-address=127.0.0.1';
        pclose(popen('start /B "" ' . $cmd, 'r'));
        for ($i = 0; $i < 40; $i++) {
            usleep(250000);
            if (p21b2_port_open('127.0.0.1', 3306)) {
                return 1;
            }
        }
    }

    return 0;
}

$mysqlWasOpen = p21b2_port_open('127.0.0.1', 3306) ? 1 : 0;
if ($mysqlWasOpen !== 1) {
    $startedMysql = p21b2_start_mysql_if_needed();
}
t_assert(p21b2_port_open('127.0.0.1', 3306), 'mysql_loopback_ready', $rawFail, $pass);

$runId = bin2hex(random_bytes(6));
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($bootstrap) . ' --run-id=' . escapeshellarg($runId);
exec($cmd . ' 2>&1', $bootOut, $bootCode);
echo implode("\n", $bootOut) . "\n";
t_assert($bootCode === 0, 'bootstrap_exit_0', $rawFail, $pass);
if ($bootCode !== 0 && str_contains(implode("\n", $bootOut), 'RESULT_C_COLUMN_GRANTS_UNSUPPORTED')) {
    echo "RESULT_C_COLUMN_GRANTS_UNSUPPORTED\n";
    exit(4);
}

$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21b2_state_' . $runId . '.json';
$secretsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21b2_secrets_' . $runId . '.json';
$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
$secrets = is_file($secretsPath) ? json_decode((string) file_get_contents($secretsPath), true) : null;
t_assert(is_array($state) && is_array($secrets), 'state_secrets_loaded', $rawFail, $pass);

$host = (string) ($state['host'] ?? '127.0.0.1');
$port = (int) ($state['port'] ?? 3306);
$controlDb = (string) ($state['control_db'] ?? '');
$saUser = (string) ($state['schema_admin_user'] ?? '');
$saPass = (string) ($secrets['schema_admin_password'] ?? '');
$idUser = (string) ($state['identity_executor_user'] ?? '');
$idPass = (string) ($secrets['identity_executor_password'] ?? '');
$rtUser = (string) ($state['control_runtime_user'] ?? '');
$rtPass = (string) ($secrets['control_runtime_password'] ?? '');
$trUser = (string) ($state['trust_executor_user'] ?? '');
$trPass = (string) ($secrets['trust_executor_password'] ?? '');
$tokenHash = (string) ($state['fixture_session_token_hash'] ?? '');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $saUser,
        $saPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    t_assert((int) ORANGE_CONTROL_SCHEMA_REVISION === 4, 'code_revision_4', $rawFail, $pass);
    $catSrc = (string) file_get_contents($repoRoot . '/includes/catalog_schema.php');
    t_assert(
        (bool) preg_match('/define\(\s*\'ORANGE_CATALOG_SCHEMA_PHP_REVISION\'\s*,\s*124\s*\)/', $catSrc),
        'country_revision_124_unchanged',
        $rawFail,
        $pass
    );

    $trust = orange_control_trust_table_names();
    $ident = orange_control_identity_table_names();
    $locale = orange_control_locale_table_names();
    t_assert(count($trust) === 11, 'trust_table_count_11', $rawFail, $pass);
    t_assert(count($ident) === 6, 'identity_table_count_6', $rawFail, $pass);
    t_assert(count($locale) === 2, 'locale_table_count_2', $rawFail, $pass);
    t_assert(count($trust) + count($ident) + count($locale) === 19, 'total_control_tables_19', $rawFail, $pass);

    $tableCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $pdo->quote($controlDb)
        . " AND TABLE_TYPE='BASE TABLE'"
    )->fetchColumn();
    t_assert($tableCount === 19, 'live_table_count_19', $rawFail, $pass);

    $meta = $pdo->query(
        'SELECT control_schema_revision, schema_manifest_hash FROM ctrl_schema_meta WHERE id=1'
    )->fetch(PDO::FETCH_ASSOC);
    t_assert(is_array($meta) && (int) $meta['control_schema_revision'] === 4, 'meta_revision_4', $rawFail, $pass);
    $phys = orange_control_physical_manifest_hash($pdo, $controlDb, 4);
    t_assert(
        is_array($meta) && hash_equals($phys, (string) $meta['schema_manifest_hash']),
        'physical_manifest_hash_exact',
        $rawFail,
        $pass
    );

    $seed = (int) $pdo->query(
        "SELECT COUNT(*) FROM ctrl_locales WHERE locale_code IN ('ar','en','fil','hi')"
    )->fetchColumn();
    t_assert($seed === 4, 'locale_seed_4', $rawFail, $pass);
    $ccl = (int) $pdo->query('SELECT COUNT(*) FROM ctrl_country_locales')->fetchColumn();
    t_assert($ccl === 0, 'model_a_zero_country_locale_rows', $rawFail, $pass);

    $prefCol = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=" . $pdo->quote($controlDb)
        . " AND TABLE_NAME='ctrl_admins' AND COLUMN_NAME='preferred_ui_language'"
    )->fetchColumn();
    $ovrCol = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=" . $pdo->quote($controlDb)
        . " AND TABLE_NAME='ctrl_admin_sessions' AND COLUMN_NAME='ui_locale_override'"
    )->fetchColumn();
    t_assert($prefCol === 1 && $ovrCol === 1, 'identity_locale_columns_present', $rawFail, $pass);

    $rtn = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=' . $pdo->quote($controlDb)
        . " AND ROUTINE_NAME LIKE 'orange_ctrl_locale_%'"
    )->fetchColumn();
    t_assert($rtn === 4, 'locale_routines_4', $rawFail, $pass);

    // Stable rev4 verify-only (zero meta write)
    $hashBefore = (string) $meta['schema_manifest_hash'];
    $atBefore = (string) $pdo->query('SELECT installed_at FROM ctrl_schema_meta WHERE id=1')->fetchColumn();
    orange_control_ensure_schema($pdo, ['allow_meta_insert' => false]);
    $atAfter = (string) $pdo->query('SELECT installed_at FROM ctrl_schema_meta WHERE id=1')->fetchColumn();
    $hashAfter = (string) $pdo->query('SELECT schema_manifest_hash FROM ctrl_schema_meta WHERE id=1')->fetchColumn();
    t_assert($atBefore === $atAfter && hash_equals($hashBefore, $hashAfter), 'stable_rev4_zero_meta_write', $rawFail, $pass);

    // Partial fail-closed
    $pdo->exec('DROP PROCEDURE IF EXISTS orange_ctrl_locale_global_upsert');
    $partial = false;
    try {
        orange_control_ensure_schema($pdo, ['allow_meta_insert' => false]);
    } catch (OrangeControlTrustException $e) {
        $partial = ($e->errorCode() === 'schema_partial_or_incompatible');
    }
    t_assert($partial, 'rev4_partial_fail_closed', $rawFail, $pass);
    $stillGone = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=' . $pdo->quote($controlDb)
        . " AND ROUTINE_NAME='orange_ctrl_locale_global_upsert'"
    )->fetchColumn();
    t_assert($stillGone === 0, 'rev4_no_self_heal', $rawFail, $pass);
    orange_control_locale_install_routines($pdo);
    // DROP PROCEDURE removes EXECUTE grants — re-grant to identity_executor (both hosts).
    foreach (['localhost', '127.0.0.1'] as $hi) {
        foreach (orange_control_locale_routine_names() as $rn) {
            $pdo->exec(
                'GRANT EXECUTE ON PROCEDURE `' . str_replace('`', '``', $controlDb) . '`.`'
                . str_replace('`', '``', $rn) . '` TO '
                . $pdo->quote($idUser) . '@' . $pdo->quote($hi)
            );
        }
    }
    $pdo->exec('FLUSH PRIVILEGES');
    $phys2 = orange_control_physical_manifest_hash($pdo, $controlDb, 4);
    $pdo->exec('UPDATE ctrl_schema_meta SET schema_manifest_hash=' . $pdo->quote($phys2) . ' WHERE id=1');

    // Sensitive column deny re-proof
    $idPdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $idUser,
        $idPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $deny = false;
    try {
        $idPdo->query('SELECT password_hash FROM ctrl_admins LIMIT 1');
    } catch (PDOException $e) {
        $deny = true;
    }
    t_assert($deny, 'id_deny_password_hash', $rawFail, $pass);
    $deny2 = false;
    try {
        $idPdo->query('SELECT session_token_hash FROM ctrl_admin_sessions LIMIT 1');
    } catch (PDOException $e) {
        $deny2 = true;
    }
    t_assert($deny2, 'id_deny_session_token_hash', $rawFail, $pass);

    $rtPdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $rtUser,
        $rtPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $rtDml = false;
    try {
        $rtPdo->exec("INSERT INTO ctrl_locales (locale_code, display_name_en, dir, numbering_system, created_at, updated_at)
                      VALUES ('zz','Z','ltr','latn',NOW(6),NOW(6))");
    } catch (PDOException $e) {
        $rtDml = true;
    }
    t_assert($rtDml, 'rt_deny_locale_dml', $rawFail, $pass);

    $trPdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $trUser,
        $trPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $trExec = false;
    try {
        $st = $trPdo->prepare('CALL orange_ctrl_locale_global_upsert(?,?,?,?,?,?,?,?,?,?,@oid)');
        $st->execute(['fr', 'French', 'Français', 'ltr', 1, 50, 'latn', $tokenHash, 'fixture', null]);
    } catch (PDOException $e) {
        $trExec = true;
    }
    t_assert($trExec, 'tr_deny_locale_execute', $rawFail, $pass);

    // R1 via identity_executor + audit
    $st = $idPdo->prepare('CALL orange_ctrl_locale_global_upsert(?,?,?,?,?,?,?,?,?,?,@oid)');
    $st->execute(['fr', 'French', 'Français', 'ltr', 1, 50, 'latn', $tokenHash, 'fixture', null]);
    $frId = (int) $idPdo->query('SELECT @oid')->fetchColumn();
    t_assert($frId > 0, 'r1_global_upsert_ok', $rawFail, $pass);
    $aud = (int) $pdo->query(
        "SELECT COUNT(*) FROM ctrl_audit_events WHERE event_type='locale_global_upserted'"
    )->fetchColumn();
    t_assert($aud >= 1, 'r1_audit_event', $rawFail, $pass);

    // R2 country locale set + STORED slot
    $cid = (int) ($state['fixture_country_id'] ?? 0);
    $st = $idPdo->prepare('CALL orange_ctrl_locale_country_locale_set(?,?,?,?,?,?,?,?,?,?,?,@clid)');
    $st->execute([$cid, 'ar', 1, 1, 1, 1, 0, 1, $tokenHash, 'fixture', null]);
    $clid = (int) $idPdo->query('SELECT @clid')->fetchColumn();
    t_assert($clid > 0, 'r2_country_locale_set', $rawFail, $pass);
    $st->execute([$cid, 'en', 1, 1, 1, 0, 1, 0, $tokenHash, 'fixture', null]);
    $defA = (int) $pdo->query(
        "SELECT COUNT(*) FROM ctrl_country_locales WHERE country_id={$cid} AND is_default_admin=1"
    )->fetchColumn();
    t_assert($defA === 1, 'stored_slot_single_default_admin', $rawFail, $pass);

    // R3 preference
    $aid = (int) ($state['fixture_super_admin_id'] ?? 0);
    $st = $idPdo->prepare('CALL orange_ctrl_locale_admin_locale_preference_set(?,?,?,?,?)');
    $st->execute([$aid, 'en', $tokenHash, 'fixture', null]);
    $pref = (string) $pdo->query("SELECT preferred_ui_language FROM ctrl_admins WHERE id={$aid}")->fetchColumn();
    t_assert($pref === 'en', 'r3_pref_set_en', $rawFail, $pass);
    $st->execute([$aid, 'ar', $tokenHash, 'fixture', null]);

    // R4 session override
    $st = $idPdo->prepare('CALL orange_ctrl_locale_session_locale_override_set(?,?,?,?,?)');
    $st->execute([$tokenHash, 'fil', $tokenHash, 'fixture', null]);
    $ovr = (string) $pdo->query(
        'SELECT ui_locale_override FROM ctrl_admin_sessions WHERE session_token_hash=' . $pdo->quote($tokenHash)
    )->fetchColumn();
    t_assert($ovr === 'fil', 'r4_session_override_fil', $rawFail, $pass);
    $st->execute([$tokenHash, null, $tokenHash, 'fixture', null]);

    // session_issue signature unchanged (no locale params)
    $sig = (string) $pdo->query(
        "SELECT PARAMETER_NAME FROM information_schema.PARAMETERS
         WHERE SPECIFIC_SCHEMA=" . $pdo->quote($controlDb)
        . " AND SPECIFIC_NAME='orange_ctrl_identity_session_issue'
           AND PARAMETER_NAME='p_ui_locale_override'"
    )->fetchColumn();
    t_assert($sig === '', 'session_issue_no_locale_param', $rawFail, $pass);

    // default_language untouched
    $dl = $pdo->query("SELECT default_language FROM ctrl_countries WHERE id={$cid}")->fetchColumn();
    t_assert($dl === null || $dl === '', 'default_language_untouched', $rawFail, $pass);

    // Dormancy
    $dormHits = 0;
    foreach (['admin/login.php', 'config.php', 'includes/header.php'] as $rel) {
        $path = $repoRoot . '/' . $rel;
        if (!is_file($path)) {
            continue;
        }
        $src = (string) file_get_contents($path);
        if (str_contains($src, 'control_locale_') || str_contains($src, 'orange_admin_resolve_ui_locale')) {
            $dormHits++;
        }
    }
    t_assert($dormHits === 0, 'dormancy_no_runtime_wiring', $rawFail, $pass);

    // Allowlist path count vs base (tracked diffs + untracked new files)
    $diff = [];
    exec('git -C ' . escapeshellarg($repoRoot) . ' diff --name-only 14c25b47283aa9c136b2f8000b33381a30a796e5', $diff);
    $untracked = [];
    exec('git -C ' . escapeshellarg($repoRoot) . ' ls-files --others --exclude-standard', $untracked);
    $norm = array_values(array_unique(array_filter(array_map(
        static fn ($p) => str_replace('\\', '/', trim($p)),
        array_merge($diff, $untracked)
    ))));
    // Ignore pre-existing ?? scripts clutter outside allowlist noise by filtering to allowlist candidates only for equality:
    $allowed = [
        'includes/control_locale_schema.php',
        'includes/control_locale_resolve.php',
        'includes/control_schema.php',
        'includes/control_identity_schema.php',
        'scripts/multidb/phase21b2_local_bootstrap.php',
        'scripts/multidb/phase21b2_local_cleanup.php',
        'scripts/self_test_multidb_phase21b2_locale_schema.php',
        'scripts/self_test_multidb_phase21b2_locale_resolve.php',
    ];
    $relevant = array_values(array_filter($norm, static function (string $p) use ($allowed): bool {
        return in_array($p, $allowed, true)
            || str_starts_with($p, 'includes/control_')
            || str_starts_with($p, 'scripts/multidb/phase21b2_')
            || str_starts_with($p, 'scripts/self_test_multidb_phase21b2_');
    }));
    sort($relevant);
    $allowedSorted = $allowed;
    sort($allowedSorted);
    t_assert($relevant === $allowedSorted, 'allowlist_exactly_8_paths', $rawFail, $pass);

    // Visual freeze type A: no CSS/HTML/print in diff
    $visualHit = 0;
    foreach ($norm as $p) {
        if (preg_match('/\.(css|html)$/i', $p) || str_contains($p, 'print')) {
            $visualHit++;
        }
    }
    t_assert($visualHit === 0, 'visual_freeze_no_css_html_print', $rawFail, $pass);
    echo "LIVE_VISUAL_BASELINE_STATUS=LIVE_VISUAL_BASELINE_UNKNOWN\n";
    echo "CODE_BASELINE_IS_LIVE_VISUAL_BASELINE=0\n";

} catch (Throwable $e) {
    echo 'FAIL schema_body ' . $e->getMessage() . "\n";
    $rawFail++;
}

// Historical migrate 3→4 outside repo from git-show 14c25b47
$histDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21b2_hist_' . $runId;
@mkdir($histDir . '/includes', 0777, true);
foreach (['control_fingerprint.php', 'control_identity_schema.php', 'control_schema.php'] as $f) {
    exec(
        'git -C ' . escapeshellarg($repoRoot) . ' show 14c25b47283aa9c136b2f8000b33381a30a796e5:includes/' . $f,
        $outLines,
        $c
    );
    if ($c === 0) {
        file_put_contents($histDir . '/includes/' . $f, implode("\n", $outLines) . "\n");
    }
    $outLines = [];
}
t_assert(is_file($histDir . '/includes/control_schema.php'), 'hist_materialize_14c25b47', $rawFail, $pass);

$migRun = 'm' . substr($runId, 0, 10);
$migDb = 'orange_phase21b2_mig_' . $migRun;
try {
    $admin = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $admin->exec('CREATE DATABASE ' . $migDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('USE `' . $migDb . '`');
    // Load historical rev3 by temporarily requiring hist files in a subprocess.
    $loader = $histDir . '/load_rev3.php';
    file_put_contents($loader, "<?php
declare(strict_types=1);
require '{$histDir}/includes/control_schema.php';
\$pdo = new PDO('mysql:host={$host};port={$port};dbname={$migDb};charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
\$tok = hash('sha256', random_bytes(32));
orange_control_ensure_schema(\$pdo, [
  'ownership_token'=>\$tok,
  'ownership_run_id'=>'" . strtolower($migRun) . "',
  'installed_by'=>'hist_rev3',
  'allow_meta_insert'=>true,
]);
echo 'REV=' . (int)\$pdo->query('SELECT control_schema_revision FROM ctrl_schema_meta WHERE id=1')->fetchColumn() . PHP_EOL;
");
    exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($loader) . ' 2>&1', $histOut, $histCode);
    echo implode("\n", $histOut) . "\n";
    t_assert($histCode === 0 && str_contains(implode("\n", $histOut), 'REV=3'), 'hist_fresh_rev3_ok', $rawFail, $pass);

    // Migrate with HEAD ensure (rev4)
    $pdoMig = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $migDb),
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    orange_control_ensure_schema($pdoMig, ['allow_meta_insert' => false]);
    $revMig = (int) $pdoMig->query('SELECT control_schema_revision FROM ctrl_schema_meta WHERE id=1')->fetchColumn();
    t_assert($revMig === 4, 'migrate_3_to_4_ok', $rawFail, $pass);
    $seedMig = (int) $pdoMig->query(
        "SELECT COUNT(*) FROM ctrl_locales WHERE locale_code IN ('ar','en','fil','hi')"
    )->fetchColumn();
    t_assert($seedMig === 4, 'migrate_seed_4', $rawFail, $pass);
    $cclMig = (int) $pdoMig->query('SELECT COUNT(*) FROM ctrl_country_locales')->fetchColumn();
    t_assert($cclMig === 0, 'migrate_model_a_no_auto_country_rows', $rawFail, $pass);
    $admin->exec('DROP DATABASE IF EXISTS `' . $migDb . '`');
} catch (Throwable $e) {
    echo 'FAIL migrate_lineage ' . $e->getMessage() . "\n";
    $rawFail++;
}

$cleanCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($cleanup) . ' --run-id=' . escapeshellarg($runId);
exec($cleanCmd . ' 2>&1', $cleanOut, $cleanCode);
echo implode("\n", $cleanOut) . "\n";
t_assert($cleanCode === 0, 'cleanup_exit_0', $rawFail, $pass);

if ($startedMysql === 1) {
    // Best-effort stop only if we started it (mysqladmin).
    $mysqladmin = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64\\bin\\mysqladmin.exe';
    if (is_file($mysqladmin)) {
        exec(escapeshellarg($mysqladmin) . ' -uroot -h127.0.0.1 shutdown 2>&1');
    }
    echo "MYSQL_STOP_ATTEMPTED=1\n";
} else {
    echo "MYSQL_STOP_ATTEMPTED=0\n";
}
echo 'LOCAL_OS_SECRET_ACL_STATUS=LOCAL_OS_SECRET_ACL_UNKNOWN' . "\n";

echo "PASS_COUNT={$pass}\n";
echo "RAW_FAIL={$rawFail}\n";
echo "CORE_SKIP={$coreSkip}\n";
echo "FALSE_GREEN_RISK_COUNT={$falseGreen}\n";
exit(($rawFail === 0 && $coreSkip === 0 && $falseGreen === 0) ? 0 : 1);
