<?php

declare(strict_types=1);

/**
 * D5 isolated worker: Country Shadow → Shadow Verify → Dry Run.
 * Args: runtime_root package_id country_code result_json_path
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$runtimeRoot = (string) ($argv[1] ?? '');
$packageId = (string) ($argv[2] ?? '');
$countryCode = (string) ($argv[3] ?? 'kw');
$resultPath = (string) ($argv[4] ?? '');
if ($runtimeRoot === '' || $packageId === '' || $resultPath === '') {
    fwrite(STDERR, "Usage: country_shadow_worker runtime package_id country result.json\n");
    exit(2);
}

require_once $runtimeRoot . '/config.php';
require_once $runtimeRoot . '/includes/catalog_schema.php';
require_once $runtimeRoot . '/includes/backup/country_crp_drv.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_country_shadow.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_country_shadow_verify.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_country_dry_run.php';

$out = ['ok' => false, 'error' => 'unknown'];
try {
    $flagPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d5_schema_ok_shw_' . getmypid() . '.flag';
    file_put_contents($flagPath, '124');
    putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $flagPath);
    $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $flagPath;

    if (!defined('ORANGE_CRP_ALLOW_TEST_OVERRIDES')) {
        define('ORANGE_CRP_ALLOW_TEST_OVERRIDES', true);
    }
    $GLOBALS['orange_country_shadow_skip_session_assert'] = true;

    $env = orange_backup_load_env_array($runtimeRoot);
    $backupRoot = orange_backup_resolve_root($env);
    $workRoot = orange_restore_resolve_work_root($env);

    $shadow = orange_country_shadow_run([
        'project_root' => $runtimeRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'package_id' => $packageId,
        'country_code' => $countryCode,
        'env' => $env,
    ]);

    $verify = null;
    $dry = null;
    if (!empty($shadow['ok'])) {
        $runId = (string) ($shadow['run_id'] ?? '');
        // C7/C8 APIs take job_id (= country shadow run_id).
        $verify = orange_country_shadow_verify_run([
            'project_root' => $runtimeRoot,
            'work_root' => $workRoot,
            'job_id' => $runId,
            'backup_root' => $backupRoot,
        ]);
        // Disposable D5 "production" is the runtime orange_db. Use the existing
        // gated live read-only inventory path (SELECT counts only; writes snapshot
        // under work_root only) — same contract as C8 F-04 when no certified file.
        $dry = orange_country_dry_run_execute([
            'project_root' => $runtimeRoot,
            'work_root' => $workRoot,
            'job_id' => $runId,
            'backup_root' => $backupRoot,
            'inject' => [
                'allow_live_prod_inventory_read' => true,
            ],
        ]);
    }

    $out = [
        'ok' => !empty($shadow['ok']) && !empty($verify['ok']) && (
            !empty($dry['ok']) || (string) ($dry['overall_result'] ?? '') === 'pass'
        ),
        'production_enabled' => ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED,
        'shadow_raw_keys' => array_keys(is_array($shadow) ? $shadow : []),
        'shadow_message' => (string) ($shadow['message'] ?? $shadow['error'] ?? ''),
        'shadow_report_codes' => $shadow['report']['blocking_reason_codes'] ?? ($shadow['blocking_reason_codes'] ?? []),
        'shadow' => [
            'ok' => !empty($shadow['ok']),
            'status' => (string) ($shadow['status'] ?? ''),
            'code' => (string) ($shadow['code'] ?? ''),
            'run_id' => (string) ($shadow['run_id'] ?? ''),
            'shadow_db' => (string) ($shadow['shadow_db'] ?? ''),
            'production_touched' => (bool) ($shadow['production_touched'] ?? false),
            'blocking' => $shadow['report']['blocking_reason_codes'] ?? ($shadow['blocking_reason_codes'] ?? []),
            'failure_detail' => substr(json_encode($shadow['report'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 800),
        ],
        'verify' => [
            'ok' => !empty($verify['ok']),
            'code' => (string) ($verify['code'] ?? ''),
            'overall' => (string) ($verify['overall_result'] ?? $verify['status'] ?? ''),
            'blockers' => $verify['blocking_reason_codes'] ?? ($verify['blockers'] ?? []),
            'schema_column_missing' => (static function (mixed $verify): array {
                $out = [];
                if (!is_array($verify)) {
                    return $out;
                }
                $checks = $verify['report']['checks'] ?? ($verify['checks'] ?? []);
                if (!is_array($checks)) {
                    return $out;
                }
                foreach ($checks as $chk) {
                    if (!is_array($chk)) {
                        continue;
                    }
                    if ((string) ($chk['code'] ?? '') === 'schema_column_missing') {
                        $out[] = (string) ($chk['detail'] ?? $chk['id'] ?? '');
                    }
                }
                return $out;
            })($verify),
            'detail' => substr(json_encode($verify ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 1200),
        ],
        'dry_run' => [
            'ok' => !empty($dry['ok']) || strtolower((string) ($dry['overall_result'] ?? '')) === 'pass',
            'overall' => (string) ($dry['overall_result'] ?? $dry['status'] ?? ''),
            'production_touched' => (bool) ($dry['production_touched'] ?? false),
            'blockers' => $dry['blocking_reason_codes'] ?? ($dry['blockers'] ?? []),
            'detail' => substr(json_encode($dry ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 1200),
        ],
        'error' => '',
    ];
    if (empty($out['ok'])) {
        $out['error'] = 'shadow=' . (string) ($out['shadow']['code'] ?: ($out['shadow']['status'] ?? ''))
            . ' verify=' . (string) ($out['verify']['overall'] ?? '')
            . ' dry=' . (string) ($out['dry_run']['overall'] ?? '')
            . ' vblock=' . json_encode($out['verify']['blockers'] ?? [])
            . ' dblock=' . json_encode($out['dry_run']['blockers'] ?? []);
    }
} catch (Throwable $e) {
    $out = [
        'ok' => false,
        'error' => $e->getMessage(),
        'exception_class' => $e::class,
    ];
}

file_put_contents($resultPath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit(!empty($out['ok']) ? 0 : 1);
