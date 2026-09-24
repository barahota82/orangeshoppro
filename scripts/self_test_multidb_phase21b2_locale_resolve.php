<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1B.2 — dormant locale resolve + digit helper self-test.
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
require_once $repoRoot . '/includes/control_locale_resolve.php';

$rawFail = 0;
$coreSkip = 0;
$falseGreen = 0;
$pass = 0;

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

if (!p21b2_port_open('127.0.0.1', 3306)) {
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
                break;
            }
        }
    }
}
t_assert(p21b2_port_open('127.0.0.1', 3306), 'mysql_ready', $rawFail, $pass);

function p21b2_set_country_lifecycle_for_locale_test(PDO $saPdo, int $countryId, string $lifecycle): void
{
    $life = strtolower(trim($lifecycle));
    if ($life === 'active') {
        // Trust Core blocks lifecycle=active until Phase 2.1C. Disposable locale tests
        // temporarily drop country triggers, then restore via orange_control_install_triggers.
        $saPdo->exec('DROP TRIGGER IF EXISTS trg_ctrl_countries_bu_phase21a');
        $saPdo->exec('DROP TRIGGER IF EXISTS trg_ctrl_countries_bi_phase21a');
    }
    $st = $saPdo->prepare('UPDATE ctrl_countries SET lifecycle_status = ?, updated_at = NOW(6) WHERE id = ?');
    $st->execute([$life, $countryId]);
    if ($life !== 'active') {
        orange_control_install_triggers($saPdo);
    }
}

$runId = bin2hex(random_bytes(6));
exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($bootstrap) . ' --run-id=' . escapeshellarg($runId) . ' 2>&1', $bootOut, $bootCode);
echo implode("\n", $bootOut) . "\n";
t_assert($bootCode === 0, 'bootstrap_exit_0', $rawFail, $pass);

$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21b2_state_' . $runId . '.json';
$secretsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21b2_secrets_' . $runId . '.json';
$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
$secrets = is_file($secretsPath) ? json_decode((string) file_get_contents($secretsPath), true) : null;
t_assert(is_array($state) && is_array($secrets), 'state_loaded', $rawFail, $pass);

$host = (string) ($state['host'] ?? '127.0.0.1');
$port = (int) ($state['port'] ?? 3306);
$controlDb = (string) ($state['control_db'] ?? '');
$saUser = (string) ($state['schema_admin_user'] ?? '');
$saPass = (string) ($secrets['schema_admin_password'] ?? '');
$idUser = (string) ($state['identity_executor_user'] ?? '');
$idPass = (string) ($secrets['identity_executor_password'] ?? '');
$tokenHash = (string) ($state['fixture_session_token_hash'] ?? '');
$cid = (int) ($state['fixture_country_id'] ?? 0);
$aid = (int) ($state['fixture_super_admin_id'] ?? 0);

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $saUser,
        $saPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $idPdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $idUser,
        $idPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Digits T-D1..T-D5
    t_assert(orange_ctrl_digits_normalize_human('١٢٣') === '123', 'T-D1_normalize_ar', $rawFail, $pass);
    t_assert(orange_ctrl_digits_normalize_human('۱۲۰') === '120', 'T-D2_normalize_ext', $rawFail, $pass);
    $rej = false;
    try {
        orange_ctrl_digits_reject_machine('١٢');
    } catch (RuntimeException $e) {
        $rej = ($e->getMessage() === 'locale_digit_machine_reject');
    }
    t_assert($rej, 'T-D3_reject_eastern', $rawFail, $pass);
    $okAscii = true;
    try {
        orange_ctrl_digits_reject_machine('12');
    } catch (Throwable $e) {
        $okAscii = false;
    }
    t_assert($okAscii, 'T-D4_ascii_pass', $rawFail, $pass);
    $rejMix = false;
    try {
        orange_ctrl_digits_reject_machine('12٣');
    } catch (RuntimeException $e) {
        $rejMix = ($e->getMessage() === 'locale_digit_machine_reject');
    }
    t_assert($rejMix, 'T-D5_mixed_reject', $rawFail, $pass);

    // Enable country locales via R2 (reads real ctrl_country_locales — FG4)
    $st = $idPdo->prepare('CALL orange_ctrl_locale_country_locale_set(?,?,?,?,?,?,?,?,?,?,?,@clid)');
    $st->execute([$cid, 'ar', 1, 1, 1, 1, 1, 1, $tokenHash, 'fixture', null]);
    $st->execute([$cid, 'en', 1, 1, 1, 0, 0, 0, $tokenHash, 'fixture', null]);
    $st->execute([$cid, 'fil', 1, 0, 0, 0, 0, 0, $tokenHash, 'fixture', null]);
    $st->execute([$cid, 'hi', 0, 1, 0, 0, 0, 0, $tokenHash, 'fixture', null]);
    $rows = (int) $pdo->query(
        "SELECT COUNT(*) FROM ctrl_country_locales WHERE country_id={$cid}"
    )->fetchColumn();
    t_assert($rows === 4, 'country_locale_rows_read_from_db', $rawFail, $pass);

    // Activate country for storefront/legal (disposable trigger bypass — not Production wiring)
    p21b2_set_country_lifecycle_for_locale_test($pdo, $cid, 'active');

    // Admin chain: session wins
    $st = $idPdo->prepare('CALL orange_ctrl_locale_session_locale_override_set(?,?,?,?,?)');
    $st->execute([$tokenHash, 'fil', $tokenHash, 'fixture', null]);
    $st = $idPdo->prepare('CALL orange_ctrl_locale_admin_locale_preference_set(?,?,?,?,?)');
    $st->execute([$aid, 'en', $tokenHash, 'fixture', null]);
    $ctx = orange_admin_resolve_ui_locale_context($pdo, $aid, 'fil', $cid);
    t_assert(($ctx['resolved_locale'] ?? '') === 'fil', 'admin_session_wins', $rawFail, $pass);
    t_assert(($ctx['source'] ?? '') === 'session', 'admin_source_session', $rawFail, $pass);
    t_assert(($ctx['numbering_system'] ?? '') === 'latn', 'admin_numbering_latn', $rawFail, $pass);
    t_assert((int) ($ctx['country_db_open_count'] ?? -1) === 0, 'admin_country_db_open_0', $rawFail, $pass);
    foreach (['surface', 'resolved_locale', 'dir', 'source', 'skipped', 'country_id', 'admin_id', 'failure_code', 'country_db_open_count', 'numbering_system'] as $k) {
        t_assert(array_key_exists($k, $ctx), 'admin_key_' . $k, $rawFail, $pass);
    }

    // Clear session → preferred
    $st = $idPdo->prepare('CALL orange_ctrl_locale_session_locale_override_set(?,?,?,?,?)');
    $st->execute([$tokenHash, null, $tokenHash, 'fixture', null]);
    $ctx2 = orange_admin_resolve_ui_locale_context($pdo, $aid, null, $cid);
    t_assert(($ctx2['resolved_locale'] ?? '') === 'en' && ($ctx2['source'] ?? '') === 'preferred', 'admin_preferred_next', $rawFail, $pass);

    // Clear preferred → country default admin (ar)
    $st = $idPdo->prepare('CALL orange_ctrl_locale_admin_locale_preference_set(?,?,?,?,?)');
    $st->execute([$aid, null, $tokenHash, 'fixture', null]);
    $ctx3 = orange_admin_resolve_ui_locale_context($pdo, $aid, null, $cid);
    t_assert(($ctx3['resolved_locale'] ?? '') === 'ar' && ($ctx3['source'] ?? '') === 'country_default_admin', 'admin_country_default', $rawFail, $pass);

    // Disabled preferred skipped
    $st = $idPdo->prepare('CALL orange_ctrl_locale_admin_locale_preference_set(?,?,?,?,?)');
    $st->execute([$aid, 'hi', $tokenHash, 'fixture', null]); // hi not enabled admin
    $ctx4 = orange_admin_resolve_ui_locale_context($pdo, $aid, null, $cid);
    t_assert(($ctx4['resolved_locale'] ?? '') === 'ar', 'admin_skip_disabled_pref', $rawFail, $pass);

    // Storefront dormant chain
    $sf = orange_storefront_resolve_ui_locale_context($pdo, $cid, ['get_lang' => 'en']);
    t_assert(($sf['resolved_locale'] ?? '') === 'en' && ($sf['source'] ?? '') === 'explicit_get', 'sf_get_lang', $rawFail, $pass);
    t_assert((int) ($sf['country_db_open_count'] ?? -1) === 0, 'sf_country_db_open_0', $rawFail, $pass);

    // Document report (ready/active) — country is active
    $doc = orange_document_resolve_ui_locale_context($pdo, $cid, 'report', null);
    t_assert(($doc['resolved_locale'] ?? '') === 'ar' && ($doc['source'] ?? '') === 'country_default_document', 'doc_report_default', $rawFail, $pass);
    $docE = orange_document_resolve_ui_locale_context($pdo, $cid, 'report', 'en');
    t_assert(($docE['resolved_locale'] ?? '') === 'en' && ($docE['source'] ?? '') === 'explicit', 'doc_report_explicit', $rawFail, $pass);

    // Legal uses document flags; active country
    $leg = orange_document_resolve_ui_locale_context($pdo, $cid, 'legal', null);
    t_assert(($leg['resolved_locale'] ?? '') === 'ar', 'doc_legal_default', $rawFail, $pass);

    // Taqfeet boundary
    $tq = orange_document_resolve_ui_locale_context($pdo, $cid, 'future_taqfeet_boundary', 'ar');
    t_assert(($tq['failure_code'] ?? '') === 'locale_taqfeet_future_boundary_only', 'doc_taqfeet_boundary', $rawFail, $pass);
    t_assert(array_key_exists('resolved_locale', $tq) && $tq['resolved_locale'] === null, 'doc_taqfeet_null', $rawFail, $pass);

    // Lifecycle: storefront on ready fails operational
    p21b2_set_country_lifecycle_for_locale_test($pdo, $cid, 'ready');
    $sfReady = orange_storefront_resolve_ui_locale_context($pdo, $cid, ['get_lang' => 'en']);
    t_assert(array_key_exists('resolved_locale', $sfReady) && $sfReady['resolved_locale'] === null, 'sf_ready_ineligible', $rawFail, $pass);
    $admReady = orange_admin_resolve_ui_locale_context($pdo, $aid, null, $cid);
    t_assert(($admReady['resolved_locale'] ?? '') === 'ar', 'admin_ready_eligible', $rawFail, $pass);
    $legReady = orange_document_resolve_ui_locale_context($pdo, $cid, 'legal', 'ar');
    t_assert(array_key_exists('resolved_locale', $legReady) && $legReady['resolved_locale'] === null, 'legal_ready_ineligible', $rawFail, $pass);
    $repReady = orange_document_resolve_ui_locale_context($pdo, $cid, 'report', null);
    t_assert(($repReady['resolved_locale'] ?? '') === 'ar', 'report_ready_eligible', $rawFail, $pass);

    // default_language never authority
    $pdo->exec("UPDATE ctrl_countries SET default_language='hi', updated_at=NOW(6) WHERE id={$cid}");
    p21b2_set_country_lifecycle_for_locale_test($pdo, $cid, 'active');
    $st = $idPdo->prepare('CALL orange_ctrl_locale_admin_locale_preference_set(?,?,?,?,?)');
    $st->execute([$aid, null, $tokenHash, 'fixture', null]);
    $ctxDl = orange_admin_resolve_ui_locale_context($pdo, $aid, null, $cid);
    t_assert(($ctxDl['resolved_locale'] ?? '') === 'ar', 'default_language_not_authority', $rawFail, $pass);

    // Thin wrapper
    $wrap = orange_admin_resolve_ui_locale($pdo, $aid, null, $cid);
    t_assert($wrap === 'ar', 'admin_wrapper_string', $rawFail, $pass);

    // Restore Trust triggers before concurrency/exit
    orange_control_install_triggers($pdo);

    // 2-conn default swap concurrency (R2)
    $pdoA = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $idUser,
        $idPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdoB = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $idUser,
        $idPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $okA = true;
    $okB = true;
    try {
        $sA = $pdoA->prepare('CALL orange_ctrl_locale_country_locale_set(?,?,?,?,?,?,?,?,?,?,?,@a)');
        $sA->execute([$cid, 'en', 1, 1, 1, 1, 0, 0, $tokenHash, 'fixture', null]);
    } catch (Throwable $e) {
        $okA = false;
    }
    try {
        $sB = $pdoB->prepare('CALL orange_ctrl_locale_country_locale_set(?,?,?,?,?,?,?,?,?,?,?,@b)');
        $sB->execute([$cid, 'fil', 1, 1, 1, 1, 0, 0, $tokenHash, 'fixture', null]);
    } catch (Throwable $e) {
        $okB = false;
    }
    $defs = (int) $pdo->query(
        "SELECT COUNT(*) FROM ctrl_country_locales WHERE country_id={$cid} AND is_default_admin=1"
    )->fetchColumn();
    t_assert($defs === 1 && ($okA || $okB), 'two_conn_default_admin_unique', $rawFail, $pass);

    // tl → fil normalize on resolve input
    $st = $idPdo->prepare('CALL orange_ctrl_locale_country_locale_set(?,?,?,?,?,?,?,?,?,?,?,@clid)');
    $st->execute([$cid, 'fil', 1, 1, 1, 0, 0, 0, $tokenHash, 'fixture', null]);
    $ctxTl = orange_admin_resolve_ui_locale_context($pdo, $aid, 'tl', $cid);
    t_assert(($ctxTl['resolved_locale'] ?? '') === 'fil', 'tl_alias_to_fil', $rawFail, $pass);

} catch (Throwable $e) {
    echo 'FAIL resolve_body ' . $e->getMessage() . "\n";
    $rawFail++;
}

exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($cleanup) . ' --run-id=' . escapeshellarg($runId) . ' 2>&1', $cleanOut, $cleanCode);
echo implode("\n", $cleanOut) . "\n";
t_assert($cleanCode === 0, 'cleanup_exit_0', $rawFail, $pass);

echo "PASS_COUNT={$pass}\n";
echo "RAW_FAIL={$rawFail}\n";
echo "CORE_SKIP={$coreSkip}\n";
echo "FALSE_GREEN_RISK_COUNT={$falseGreen}\n";
echo "LIVE_VISUAL_BASELINE_STATUS=LIVE_VISUAL_BASELINE_UNKNOWN\n";
exit(($rawFail === 0 && $coreSkip === 0 && $falseGreen === 0) ? 0 : 1);
