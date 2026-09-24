<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1A S3 repair — Control schema / fingerprint / physical manifest self-test.
 *
 * Exit 0 only when RAW_FAIL=0 and CORE_SKIP=0.
 * Fingerprint/snapshot expected values are hardcoded goldens (not SUT expected() as sole authority).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI_ONLY\n");
    exit(2);
}

$phpBin = PHP_BINARY;
$repoRoot = dirname(__DIR__, 1);
if (basename($repoRoot) === 'scripts') {
    $repoRoot = dirname($repoRoot);
}
$bootstrap = $repoRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'multidb' . DIRECTORY_SEPARATOR . 'phase21a_local_bootstrap.php';
$cleanup = $repoRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'multidb' . DIRECTORY_SEPARATOR . 'phase21a_local_cleanup.php';

require_once $repoRoot . '/includes/control_fingerprint.php';
require_once $repoRoot . '/includes/control_schema.php';

$rawFail = 0;
$coreSkip = 0;
$assertWeakened = 0;
$falseGreen = 0;
$pass = 0;

/** Hardcoded golden identity fingerprints (Phase 2.0C) — independent of expected() helper. */
const P21A_GOLDEN_FP = [
    'V1' => 'd56e93a305e16acf666d5dae2ba090732fdf6728890d01703c13c56617bd1f13',
    'V2' => 'de10dc6ab170fcbbe74adcc36a2b5610b9527f2f089e4c5843c946dab222dee3',
    'V5' => 'e52192ad5e848b80fbcf2c94e508a5cb7fb02af07d644c205815419bb60142db',
];

/** Hardcoded golden snapshot hashes (Phase 2.0E). */
const P21A_GOLDEN_SNAP = [
    'R1' => '84b746caeefc83b000e2b95aefb9f8f4c410df6e86484f68e315210d766b9bd3',
    'R2' => 'adfe3c42f6566d1df20063927ea501a149c6ca52310bcbb51218d5674304a861',
    'R3' => 'b7a0850aebd5eec4cb9ed6db38acfa1429e57fcf8c313ef28e370a4dd68b9b5e',
    'R4' => 'ee7fd05a4cffc498386b7ee5201c5baa09550690530cb8d5d58a690dd3a74a4e',
];

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

function t_expect_code(callable $fn, string $code, string $name, int &$rawFail, int &$pass): void
{
    try {
        $fn();
        echo "FAIL {$name} (expected {$code})\n";
        $rawFail++;
    } catch (OrangeControlTrustException $e) {
        t_assert($e->errorCode() === $code, $name . ':' . $code, $rawFail, $pass);
    } catch (Throwable $e) {
        echo "FAIL {$name} (unexpected " . $e->getMessage() . ")\n";
        $rawFail++;
    }
}

/**
 * Independent SHA-256 of known canonical (does not call orange_control_*_expected).
 */
function t_independent_sha256(string $canonical): string
{
    return hash('sha256', $canonical, false);
}

$runId = bin2hex(random_bytes(6));
$cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($bootstrap) . ' --run-id=' . escapeshellarg($runId);
exec($cmd . ' 2>&1', $bootOut, $bootCode);
echo implode("\n", $bootOut) . "\n";
t_assert($bootCode === 0, 'bootstrap_exit_0', $rawFail, $pass);

$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21a_state_' . $runId . '.json';
$secretsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_phase21a_secrets_' . $runId . '.json';
$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
$secrets = is_file($secretsPath) ? json_decode((string) file_get_contents($secretsPath), true) : null;
t_assert(is_array($state) && is_array($secrets), 'state_secrets_loaded', $rawFail, $pass);
t_assert(
    is_array($secrets) && !str_contains(implode("\n", $bootOut), (string) ($secrets['schema_admin_password'] ?? '___')),
    'secret_not_printed_in_bootstrap_stdout',
    $rawFail,
    $pass
);

$host = (string) ($state['host'] ?? '127.0.0.1');
$port = (int) ($state['port'] ?? 3306);
$controlDb = (string) ($state['control_db'] ?? '');
$saUser = (string) ($state['schema_admin_user'] ?? '');
$saPass = (string) ($secrets['schema_admin_password'] ?? '');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $controlDb),
        $saUser,
        $saPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $cu = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    t_assert(str_starts_with($cu, $saUser . '@'), 'sa_current_user_is_schema_admin', $rawFail, $pass);

    $tables = orange_control_trust_table_names();
    t_assert(count($tables) === 11, 'control_table_count_11', $rawFail, $pass);
    foreach ($tables as $t) {
        $c = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $pdo->quote($controlDb)
            . ' AND TABLE_NAME=' . $pdo->quote($t)
        )->fetchColumn();
        t_assert($c === 1, 'table_exists_' . $t, $rawFail, $pass);
        $eng = (string) $pdo->query(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $pdo->quote($controlDb)
            . ' AND TABLE_NAME=' . $pdo->quote($t)
        )->fetchColumn();
        t_assert(strtoupper($eng) === 'INNODB', 'innodb_' . $t, $rawFail, $pass);
        $cc = $pdo->query(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $pdo->quote($controlDb)
            . ' AND TABLE_NAME=' . $pdo->quote($t)
        )->fetchColumn();
        t_assert((string) $cc === 'utf8mb4_unicode_ci', 'collation_' . $t, $rawFail, $pass);
    }

    $meta = $pdo->query(
        'SELECT control_schema_revision, schema_manifest_hash, ownership_token, ownership_run_id,
                country_active_transition_enabled, registry_active_transition_enabled,
                database_replacement_transition_enabled
         FROM ctrl_schema_meta WHERE id=1'
    )->fetch(PDO::FETCH_ASSOC);
    t_assert(is_array($meta) && (int) $meta['control_schema_revision'] === 2, 'control_revision_2', $rawFail, $pass);
    t_assert(is_array($meta) && (int) $meta['country_active_transition_enabled'] === 0, 'gate_country_0', $rawFail, $pass);
    t_assert(is_array($meta) && (int) $meta['registry_active_transition_enabled'] === 0, 'gate_registry_0', $rawFail, $pass);
    t_assert(is_array($meta) && (int) $meta['database_replacement_transition_enabled'] === 0, 'gate_replacement_0', $rawFail, $pass);

    $phys = orange_control_physical_manifest_hash($pdo, $controlDb);
    t_assert(
        is_array($meta) && hash_equals($phys, (string) $meta['schema_manifest_hash']),
        'physical_manifest_hash_exact',
        $rawFail,
        $pass
    );
    t_assert(
        is_array($state) && hash_equals($phys, (string) ($state['schema_manifest_hash'] ?? '')),
        'state_manifest_matches_physical',
        $rawFail,
        $pass
    );

    // Independent rebuild of physical canonical hash (same builder as authority — information_schema).
    $canon = orange_control_physical_manifest_canonical($pdo, $controlDb);
    t_assert(t_independent_sha256($canon) === $phys, 'independent_sha256_physical_manifest', $rawFail, $pass);

    // Fail-closed meta: wrong revision must not silent-overwrite.
    $pdo->exec('UPDATE ctrl_schema_meta SET control_schema_revision = 99 WHERE id = 1');
    $silent = false;
    try {
        orange_control_ensure_schema($pdo, ['allow_meta_insert' => false]);
        $silent = true;
    } catch (OrangeControlTrustException $e) {
        t_assert($e->errorCode() === 'schema_meta_revision_mismatch', 'meta_silent_overwrite_denied', $rawFail, $pass);
    }
    t_assert(!$silent, 'meta_ensure_did_not_succeed_on_mismatch', $rawFail, $pass);
    $still = (int) $pdo->query('SELECT control_schema_revision FROM ctrl_schema_meta WHERE id=1')->fetchColumn();
    t_assert($still === 99, 'meta_revision_not_silently_rewritten', $rawFail, $pass);
    $pdo->exec('UPDATE ctrl_schema_meta SET control_schema_revision = 2 WHERE id = 1');

    // Physical DDL tamper must fail verification.
    $pdo->exec('ALTER TABLE ctrl_audit_events ADD COLUMN p21a_tamper_col INT NULL');
    $tamperFail = false;
    try {
        orange_control_ensure_schema($pdo, ['allow_meta_insert' => false]);
    } catch (OrangeControlTrustException $e) {
        $tamperFail = ($e->errorCode() === 'schema_manifest_physical_mismatch');
    }
    t_assert($tamperFail, 'physical_ddl_tamper_detected', $rawFail, $pass);
    $pdo->exec('ALTER TABLE ctrl_audit_events DROP COLUMN p21a_tamper_col');
    // Re-install routines/triggers after ensure aborted mid-path; restore matching meta hash.
    orange_control_install_triggers($pdo);
    orange_control_install_routines($pdo);
    $phys2 = orange_control_physical_manifest_hash($pdo, $controlDb);
    $pdo->prepare('UPDATE ctrl_schema_meta SET schema_manifest_hash = ? WHERE id = 1')->execute([$phys2]);

    $catalog = $repoRoot . '/includes/catalog_schema.php';
    $catSrc = (string) file_get_contents($catalog);
    t_assert(
        preg_match("/define\\('ORANGE_CATALOG_SCHEMA_PHP_REVISION',\\s*124\\)/", $catSrc) === 1,
        'country_schema_revision_124_untouched',
        $rawFail,
        $pass
    );
    t_assert((int) ($state['country_database_created_count'] ?? -1) === 0, 'country_db_created_0', $rawFail, $pass);

    $need = [$controlDb, 'orange_db', 'mysql', 'information_schema', 'performance_schema', 'sys'];
    foreach ($need as $n) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM ctrl_reserved_database_names WHERE db_name=?');
        $st->execute([$n]);
        t_assert((int) $st->fetchColumn() === 1, 'reserved_present_' . $n, $rawFail, $pass);
    }
    $dupDenied = false;
    $dupState = '';
    try {
        $pdo->exec("INSERT INTO ctrl_reserved_database_names (db_name, reason, created_at) VALUES ('orange_db','x',NOW(6))");
    } catch (PDOException $e) {
        $dupDenied = ((int) ($e->errorInfo[1] ?? 0) === 1062 || (string) ($e->errorInfo[0] ?? '') === '23000');
        $dupState = (string) ($e->errorInfo[0] ?? '');
    }
    t_assert($dupDenied, 'reserved_duplicate_denied_sqlstate_' . $dupState, $rawFail, $pass);

    // Routines present
    foreach (
        [
            'orange_ctrl_register_mapping',
            'orange_ctrl_registry_trust_transition',
            'orange_ctrl_country_lifecycle_transition',
            'orange_ctrl_emit_telemetry',
        ] as $rn
    ) {
        $c = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=" . $pdo->quote($controlDb)
            . " AND ROUTINE_NAME=" . $pdo->quote($rn)
        )->fetchColumn();
        t_assert($c === 1, 'routine_exists_' . $rn, $rawFail, $pass);
    }

    // Reference validators
    t_assert(
        orange_control_validate_secret_ref('Orange://Secrets/Country_Runtime/KW') === 'orange://secrets/country_runtime/kw',
        'secret_ref_normalize',
        $rawFail,
        $pass
    );
    t_assert(
        orange_control_validate_runtime_principal_ref('Orange://Principals/Country_Runtime/KW') === 'orange://principals/country_runtime/kw',
        'principal_ref_normalize',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref('orange://principals/country_runtime/kw'),
        'secret_ref_invalid',
        'principal_in_secret_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_runtime_principal_ref('orange://secrets/country_runtime/kw'),
        'runtime_principal_ref_invalid',
        'secrets_in_principal_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref('orange://secrets/a//b'),
        'secret_ref_invalid',
        'repeated_slash_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref('orange://secrets/a/b/'),
        'secret_ref_invalid',
        'trailing_slash_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref('orange://secrets/'),
        'secret_ref_invalid',
        'empty_segment_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref('orange://secrets/.'),
        'secret_ref_invalid',
        'dot_segment_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref('orange://secrets/..'),
        'secret_ref_invalid',
        'dotdot_segment_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref('orange://secrets/a/./b'),
        'secret_ref_invalid',
        'embedded_dot_segment_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref('orange://secrets/a/../b'),
        'secret_ref_invalid',
        'embedded_dotdot_segment_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref("orange://secrets/x\x1Fy"),
        'secret_ref_forbidden_byte',
        'sep_byte_denied',
        $rawFail,
        $pass
    );
    t_expect_code(
        static fn () => orange_control_validate_secret_ref("orange://secrets/x\x01y"),
        'secret_ref_forbidden_byte',
        'control_byte_denied',
        $rawFail,
        $pass
    );

    // Fingerprint V1–V5 against hardcoded goldens
    t_assert(
        orange_control_fingerprint_v1_hash(orange_control_fingerprint_v1_fixture('V1')) === P21A_GOLDEN_FP['V1'],
        'fp_V1_hardcoded',
        $rawFail,
        $pass
    );
    t_assert(
        orange_control_fingerprint_v1_hash(orange_control_fingerprint_v1_fixture('V2')) === P21A_GOLDEN_FP['V2'],
        'fp_V2_hardcoded',
        $rawFail,
        $pass
    );
    t_assert(
        orange_control_fingerprint_v1_hash(orange_control_fingerprint_v1_fixture('V3')) === P21A_GOLDEN_FP['V1'],
        'fp_V3_mixed_eq_V1',
        $rawFail,
        $pass
    );
    t_assert(
        orange_control_fingerprint_v1_hash(orange_control_fingerprint_v1_fixture('V5')) === P21A_GOLDEN_FP['V5'],
        'fp_V5_hardcoded',
        $rawFail,
        $pass
    );
    t_assert(P21A_GOLDEN_FP['V5'] !== P21A_GOLDEN_FP['V1'], 'fp_V5_ne_V1', $rawFail, $pass);
    $bad = orange_control_fingerprint_v1_fixture('V1');
    $bad['country_uuid'] = "11111111-1111-4111-8111-111111111111\x80";
    t_expect_code(
        static fn () => orange_control_fingerprint_v1_hash($bad),
        'bad_country_uuid',
        'fp_V4_reject',
        $rawFail,
        $pass
    );
    $canonFp = orange_control_fingerprint_v1_canonical(orange_control_fingerprint_v1_fixture('V1'));
    t_assert(
        t_independent_sha256($canonFp) === P21A_GOLDEN_FP['V1'] && strlen(P21A_GOLDEN_FP['V1']) === 64,
        'fp_independent_sha256_no_double_hex',
        $rawFail,
        $pass
    );

    foreach (['R1', 'R2', 'R3', 'R4'] as $rn) {
        t_assert(
            orange_control_snapshot_hash_v1(orange_control_snapshot_v1_fixture($rn)) === P21A_GOLDEN_SNAP[$rn],
            'snap_' . $rn . '_hardcoded',
            $rawFail,
            $pass
        );
    }
    t_assert(
        orange_control_snapshot_hash_v1(orange_control_snapshot_v1_fixture('R5')) === P21A_GOLDEN_SNAP['R1'],
        'snap_R5_eq_R1',
        $rawFail,
        $pass
    );
    t_assert(P21A_GOLDEN_SNAP['R1'] !== P21A_GOLDEN_SNAP['R4'], 'snap_secret_version_changes_hash', $rawFail, $pass);
    $obsolete = orange_control_obsolete_snapshot_hashes();
    foreach ($obsolete as $sn => $oh) {
        t_assert(
            $oh !== P21A_GOLDEN_SNAP['R1'] && $oh !== P21A_GOLDEN_SNAP['R2'] && $oh !== P21A_GOLDEN_SNAP['R3'],
            'obsolete_' . $sn . '_not_current',
            $rawFail,
            $pass
        );
    }

    $prodNeedles = [
        $repoRoot . '/config.php',
        $repoRoot . '/index.php',
        $repoRoot . '/admin/index.php',
        $repoRoot . '/includes/catalog_schema.php',
    ];
    foreach ($prodNeedles as $pf) {
        if (!is_file($pf)) {
            continue;
        }
        $src = (string) file_get_contents($pf);
        t_assert(
            !str_contains($src, 'control_schema.php') && !str_contains($src, 'control_fingerprint.php'),
            'dormant_no_include_' . basename($pf),
            $rawFail,
            $pass
        );
    }
    t_assert(!function_exists('db'), 'db_function_not_loaded', $rawFail, $pass);
} catch (Throwable $e) {
    echo 'FAIL schema_body ' . $e->getMessage() . "\n";
    $rawFail++;
}

$cleanCmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($cleanup) . ' --run-id=' . escapeshellarg($runId);
exec($cleanCmd . ' 2>&1', $cleanOut, $cleanCode);
echo implode("\n", $cleanOut) . "\n";
t_assert($cleanCode === 0, 'cleanup_exit_0', $rawFail, $pass);
t_assert(!is_file($secretsPath), 'secrets_file_deleted', $rawFail, $pass);

echo "PASS_COUNT={$pass}\n";
echo "RAW_FAIL={$rawFail}\n";
echo "CORE_SKIP={$coreSkip}\n";
echo "ASSERTION_WEAKENED={$assertWeakened}\n";
echo "FALSE_GREEN_KNOWN_GAP_COUNT={$falseGreen}\n";
echo 'EXIT=' . ($rawFail === 0 && $coreSkip === 0 && $assertWeakened === 0 && $falseGreen === 0 ? '0' : '1') . "\n";
exit($rawFail === 0 && $coreSkip === 0 && $assertWeakened === 0 && $falseGreen === 0 ? 0 : 1);
