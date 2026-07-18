<?php

declare(strict_types=1);

/**
 * Phase 3B.4G — Disaster Recovery Drill self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_dr_drill.php
 *
 * Isolated fixtures only. Never touches real production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dr_drill.php';

$failures = 0;
$passes = 0;

function dr_self_test(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

echo "=== self_test_restore_dr_drill (3B.4G) ===\n";

$cliPath = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'run_restore_dr_drill.php';
dr_self_test(is_file($cliPath), 'cli_script_exists');
$cliSrc = is_file($cliPath) ? (string) file_get_contents($cliPath) : '';
dr_self_test(str_contains($cliSrc, "PHP_SAPI !== 'cli'"), 'cli_rejects_http');
dr_self_test(str_contains($cliSrc, 'arbitrary'), 'cli_rejects_arbitrary_paths');
dr_self_test(str_contains($cliSrc, '--mode='), 'cli_supports_mode');

dr_self_test(
    defined('ORANGE_RESTORE_DR_DRILL_MARKER')
    && ORANGE_RESTORE_DR_DRILL_MARKER === '.orange_restore_dr_drill_fixture',
    'isolation_marker_constant'
);
dr_self_test(
    ORANGE_RESTORE_DR_FIXTURE_PROD_DB !== 'orange_db'
    && ORANGE_RESTORE_DR_FIXTURE_SHADOW_DB !== 'orange_db'
    && strcasecmp(ORANGE_RESTORE_DR_FIXTURE_PROD_DB, ORANGE_RESTORE_DR_FIXTURE_SHADOW_DB) !== 0,
    'fixture_db_names_isolated'
);

// Isolation fence unit check
$ctx = orange_restore_dr_drill_create_environment($projectRoot);
try {
    orange_restore_dr_drill_assert_isolation(['real_project_root' => $projectRoot]);
    dr_self_test(true, 'assert_isolation_passes_on_fixture');
    dr_self_test(
        orange_restore_dr_drill_has_marker((string) $ctx['fixture_project']),
        'fixture_project_has_marker'
    );
    dr_self_test(
        orange_restore_dr_drill_has_marker((string) $ctx['backup_root']),
        'backup_root_has_marker'
    );
} catch (Throwable $e) {
    dr_self_test(false, 'assert_isolation_passes_on_fixture:' . $e->getMessage());
} finally {
    orange_restore_dr_drill_rmtree((string) $ctx['session_root']);
    orange_restore_dr_drill_clear_context();
}

// Injection registry is drill-only (no env)
$_ENV['ORANGE_RESTORE_FORCE_FAIL'] = '1';
putenv('ORANGE_RESTORE_FORCE_FAIL=1');
dr_self_test(!orange_restore_dr_drill_injection_enabled('verify_failure'), 'env_cannot_enable_injection');
orange_restore_dr_drill_set_injection('verify_failure', true);
dr_self_test(orange_restore_dr_drill_injection_enabled('verify_failure'), 'drill_api_enables_injection');
orange_restore_dr_drill_reset_injections();
putenv('ORANGE_RESTORE_FORCE_FAIL');
unset($_ENV['ORANGE_RESTORE_FORCE_FAIL']);

$ids = orange_restore_dr_drill_injection_ids();
dr_self_test(count($ids) >= 17, 'injection_ids_cover_mandatory_set');

$sec = orange_restore_dr_drill_run_security_validation($projectRoot);
dr_self_test(!empty($sec['ok']), 'security_validation_suite');

$cp = orange_restore_dr_drill_run_checkpoint_validation($projectRoot);
dr_self_test(!empty($cp['ok']), 'checkpoint_policy_validation');

// Full drill (may take a minute)
$run = orange_restore_dr_drill_run([
    'project_root' => $projectRoot,
    'mode' => 'all',
    'verbose' => false,
]);
dr_self_test(!empty($run['ok']), 'full_drill_run_ok');
$report = is_array($run['report'] ?? null) ? $run['report'] : [];
dr_self_test(($report['environment'] ?? '') === 'isolated_drill', 'report_environment_isolated');
dr_self_test(($report['country_restore_certified'] ?? true) === false, 'country_never_certified');
dr_self_test(
    in_array((string) ($report['production_execution_recommendation'] ?? ''), ['CERTIFIED', 'CONDITIONAL', 'NOT_CERTIFIED'], true),
    'recommendation_enum_valid'
);
dr_self_test(
    empty($report['confirmation']['real_production_restore_run']),
    'confirmation_no_real_production_restore'
);

$reportPath = $projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . ORANGE_RESTORE_DR_CERT_REPORT_FILE;
dr_self_test(is_file($reportPath), 'certification_json_written');

$public = orange_restore_dr_drill_read_certification_report($projectRoot);
dr_self_test(!empty($public['available']), 'admin_reader_sees_report');
dr_self_test(!empty($public['http_never_runs_drill']), 'admin_reader_marks_http_safe');
dr_self_test(
    str_contains((string) ($public['cli_command'] ?? ''), 'run_restore_dr_drill.php'),
    'admin_reader_exposes_cli_name_only'
);

$docsCert = $projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'ORANGE_DR_PRODUCTION_CERTIFICATION.md';
$docsRunbook = $projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'ORANGE_DR_OPERATOR_RUNBOOK.md';
dr_self_test(is_file($docsCert), 'certification_md_exists');
dr_self_test(is_file($docsRunbook), 'operator_runbook_exists');

echo "\nRESULT: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
