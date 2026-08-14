<?php

declare(strict_types=1);

/**
 * Step 7 auto-deploy identity sentinel — focused contract tests.
 * No live job mutation, no SQL/package scan, no UI.
 * Runtime emit cases run before any stdout so CLI header() works.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$marker = 'ORANGE_STEP7_DIAG_SENTINEL_94D_CHAIN_A1';
$headerName = 'X-Orange-Step7-Diagnostic-Sentinel';
$apiRel = 'admin/api/restore/job/orchestrator-diagnostics.php';
$apiPath = $projectRoot . '/' . $apiRel;

$pass = 0;
$fail = 0;
$lines = [];

function s7pse_ok(bool $c, string $l): void
{
    global $pass, $fail, $lines;
    $lines[] = ($c ? 'PASS ' : 'FAIL ') . $l;
    $c ? $pass++ : $fail++;
}

$GLOBALS['__s7pse_jr'] = null;

if (!function_exists('json_response')) {
    function json_response($data, int $httpCode = 200): void
    {
        $GLOBALS['__s7pse_jr'] = ['data' => $data, 'http' => $httpCode];
    }
}

if (!defined('ORANGE_STEP7_DIAG_DEPLOY_SENTINEL')) {
    define('ORANGE_STEP7_DIAG_DEPLOY_SENTINEL', $marker);
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function orange_restore_diagnostic_api_with_deploy_sentinel(array $payload): array
{
    $payload['deploy_sentinel'] = ORANGE_STEP7_DIAG_DEPLOY_SENTINEL;

    return $payload;
}

/**
 * @param array<string, mixed> $payload
 */
function orange_restore_diagnostic_api_emit(array $payload, int $http = 200): void
{
    if (!headers_sent()) {
        header('X-Orange-Step7-Diagnostic-Sentinel: ' . ORANGE_STEP7_DIAG_DEPLOY_SENTINEL);
    }
    json_response(orange_restore_diagnostic_api_with_deploy_sentinel($payload), $http);
}

// --- Runtime first (before stdout) ---
$cases = [
    'success' => [
        'payload' => [
            'success' => true,
            'safe_code' => 'ok',
            'final_readiness' => 'NOT_READY',
            'exact_not_ready_reason' => 'STEP7_DIAGNOSTIC_SQL_SCAN_FAILED',
            'step7_action_enabled' => false,
        ],
        'http' => 200,
    ],
    'structured_NOT_READY' => [
        'payload' => [
            'success' => false,
            'safe_code' => 'STEP7_DIAGNOSTIC_JOB_NOT_FOUND',
            'final_readiness' => 'NOT_READY',
            'exact_not_ready_reason' => 'STEP7_DIAGNOSTIC_JOB_NOT_FOUND',
            'step7_action_enabled' => false,
        ],
        'http' => 422,
    ],
    'safe_server_error' => [
        'payload' => [
            'success' => false,
            'safe_code' => 'STEP7_DIAGNOSTIC_RESPONSE_SERIALIZATION_FAILED',
            'final_readiness' => 'NOT_READY',
            'exact_not_ready_reason' => 'STEP7_DIAGNOSTIC_RESPONSE_SERIALIZATION_FAILED',
            'step7_action_enabled' => false,
        ],
        'http' => 500,
    ],
];

foreach ($cases as $name => $case) {
    $GLOBALS['__s7pse_jr'] = null;
    $beforeKeys = array_keys($case['payload']);
    $before = $case['payload'];
    // Field attach (same production helper body) — CLI cannot reliably assert HTTP headers.
    $attached = orange_restore_diagnostic_api_with_deploy_sentinel($case['payload']);
    orange_restore_diagnostic_api_emit($case['payload'], $case['http']);
    $jr = $GLOBALS['__s7pse_jr'];
    s7pse_ok(is_array($jr), $name . ': json_response captured');
    $data = is_array($jr) ? ($jr['data'] ?? null) : null;
    $http = is_array($jr) ? (int) ($jr['http'] ?? -1) : -1;
    s7pse_ok($http === $case['http'], $name . ': HTTP status unchanged (' . $case['http'] . ')');
    s7pse_ok(
        is_array($attached) && ($attached['deploy_sentinel'] ?? null) === $marker,
        $name . ': deploy_sentinel via attach helper'
    );
    s7pse_ok(is_array($data) && ($data['deploy_sentinel'] ?? null) === $marker, $name . ': deploy_sentinel field');
    if (is_array($data)) {
        foreach ($beforeKeys as $k) {
            s7pse_ok(
                array_key_exists($k, $data) && $data[$k] === $before[$k],
                $name . ': existing field unchanged: ' . $k
            );
        }
        s7pse_ok(($data['success'] ?? null) === $before['success'], $name . ': success semantics unchanged');
        s7pse_ok(($data['safe_code'] ?? null) === $before['safe_code'], $name . ': safe_code unchanged');
        s7pse_ok(($data['final_readiness'] ?? null) === $before['final_readiness'], $name . ': readiness unchanged');
    } else {
        s7pse_ok(false, $name . ': payload array expected');
    }
}

// --- Source / freeze contracts ---
$src = (string) file_get_contents($apiPath);

s7pse_ok(is_file($apiPath), 'orchestrator-diagnostics.php exists');
s7pse_ok(str_contains($src, $marker), 'marker string present in route');
s7pse_ok(str_contains($src, $headerName), 'sentinel header name present');
s7pse_ok(str_contains($src, "'deploy_sentinel'"), 'deploy_sentinel JSON key present');
s7pse_ok(
    str_contains($src, 'orange_restore_diagnostic_api_emit(['),
    'structured_failure emits via sentinel helper'
);
s7pse_ok(
    str_contains($src, 'orange_restore_diagnostic_api_emit($payload)'),
    'success path emits via sentinel helper'
);

$emitPos = strpos($src, 'function orange_restore_diagnostic_api_emit');
$beforeEmit = $emitPos === false ? $src : substr($src, 0, $emitPos);
$emitEnd = $emitPos === false ? false : strpos($src, "\n}", $emitPos);
$emitBody = ($emitPos !== false && $emitEnd !== false)
    ? substr($src, $emitPos, $emitEnd - $emitPos + 2)
    : '';
$afterEmitRest = ($emitEnd === false) ? '' : substr($src, $emitEnd + 2);
s7pse_ok(
    !preg_match('/\bjson_response\s*\(/', $beforeEmit)
    && preg_match('/\bjson_response\s*\(\s*orange_restore_diagnostic_api_with_deploy_sentinel/', $emitBody)
    && !preg_match('/\bjson_response\s*\(/', $afterEmitRest),
    'json_response only via with_deploy_sentinel inside emit'
);

$publicFiles = [
    'health.php',
    'index.php',
    'api/orders/create-order.php',
    'pages/home.php',
];
$publicLeak = false;
foreach ($publicFiles as $rel) {
    $p = $projectRoot . '/' . $rel;
    if (!is_file($p)) {
        continue;
    }
    $t = (string) file_get_contents($p);
    if (str_contains($t, $marker) || str_contains($t, 'deploy_sentinel') || str_contains($t, $headerName)) {
        $publicLeak = true;
        break;
    }
}
s7pse_ok(!$publicLeak, 'sentinel not on unauthenticated public routes');

$uiPage = $projectRoot . '/admin/pages/restore_center.php';
$uiSrc = is_file($uiPage) ? (string) file_get_contents($uiPage) : '';
s7pse_ok(
    $uiSrc !== '' && !str_contains($uiSrc, $marker) && !str_contains($uiSrc, 'deploy_sentinel'),
    'RESTORE_CENTER_UI sentinel display absent'
);

s7pse_ok(
    str_contains($src, "header('" . $headerName . ": ' . ORANGE_STEP7_DIAG_DEPLOY_SENTINEL)")
    || str_contains($src, 'header(\'' . $headerName . ': \' . ORANGE_STEP7_DIAG_DEPLOY_SENTINEL)'),
    'header() call wired to sentinel constant'
);
s7pse_ok(
    $emitBody !== ''
    && str_contains($emitBody, $headerName)
    && str_contains($emitBody, 'ORANGE_STEP7_DIAG_DEPLOY_SENTINEL')
    && str_contains($emitBody, 'orange_restore_diagnostic_api_with_deploy_sentinel'),
    'emit body sets sentinel header and attaches JSON field'
);
s7pse_ok(
    substr_count($src, 'orange_restore_diagnostic_api_emit(') >= 3,
    'emit used on success + structured failure paths (>=3 call sites)'
);

$emitFn = $emitBody;
s7pse_ok(
    $emitFn !== '' && !str_contains($emitFn, 'orange_restore_center_diagnostics'),
    'emit does not re-invoke diagnostic builder'
);
s7pse_ok(
    $emitFn !== '' && !str_contains($emitFn, 'orange_restore_sql_compat_scan'),
    'emit does not add SQL/package scan'
);
s7pse_ok(
    str_contains($src, "define('ORANGE_STEP7_DIAG_DEPLOY_SENTINEL', '" . $marker . "')"),
    'production constant value exact marker'
);

foreach ($lines as $line) {
    echo $line . "\n";
}
echo "\nRESULT pass={$pass} fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
