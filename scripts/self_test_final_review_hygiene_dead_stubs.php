<?php

declare(strict_types=1);

/**
 * FSR Batch F — dead public stub + login comment hygiene (no business change).
 *
 * Usage:
 *   php scripts/self_test_final_review_hygiene_dead_stubs.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$passes = 0;
$failures = 0;
$skips = 0;

function hf_pass(string $label): void
{
    global $passes;
    echo "PASS  {$label}\n";
    $passes++;
}

function hf_fail(string $label): void
{
    global $failures;
    echo "FAIL  {$label}\n";
    $failures++;
}

function hf_assert(bool $ok, string $label): void
{
    if ($ok) {
        hf_pass($label);
    } else {
        hf_fail($label);
    }
}

$deadStubs = [
    'api/stock/adjust-stock.php',
    'api/offers/get-offers.php',
];

foreach ($deadStubs as $rel) {
    hf_assert(!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel)), "deleted stub absent: {$rel}");
}

$scanRoots = [
    $root . DIRECTORY_SEPARATOR . 'api',
    $root . DIRECTORY_SEPARATOR . 'admin',
    $root . DIRECTORY_SEPARATOR . 'assets',
    $root . DIRECTORY_SEPARATOR . 'pages',
    $root . DIRECTORY_SEPARATOR . 'includes',
    $root . DIRECTORY_SEPARATOR . 'scripts',
];
$needlePatterns = [
    'api/stock/adjust-stock.php',
    '/api/stock/adjust-stock.php',
    'adjust-stock.php',
    'api/offers/get-offers.php',
    '/api/offers/get-offers.php',
    'get-offers.php',
];
$callerHits = [];
foreach ($scanRoots as $scanRoot) {
    if (!is_dir($scanRoot)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        $ext = strtolower($fileInfo->getExtension());
        if (!in_array($ext, ['php', 'js', 'html', 'htm', 'json', 'md', 'txt'], true)) {
            continue;
        }
        $path = $fileInfo->getPathname();
        // Ignore this hygiene suite and Batch F QA note (may mention deleted paths historically).
        $norm = str_replace('\\', '/', $path);
        if (str_ends_with($norm, '/self_test_final_review_hygiene_dead_stubs.php')) {
            continue;
        }
        if (str_ends_with($norm, '/ORANGE_AGENT_QA_REFERENCE.txt')) {
            continue;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            continue;
        }
        foreach ($needlePatterns as $needle) {
            if (str_contains($raw, $needle)) {
                $callerHits[] = $norm . ' :: ' . $needle;
            }
        }
    }
}
hf_assert($callerHits === [], 'no tracked PHP/JS/HTML caller references deleted stub routes');
if ($callerHits !== []) {
    foreach (array_slice($callerHits, 0, 10) as $hit) {
        echo "INFO  leftover reference: {$hit}\n";
    }
}

$routerFiles = [
    $root . DIRECTORY_SEPARATOR . 'web.config',
    $root . DIRECTORY_SEPARATOR . '.htaccess',
    $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'web.config',
    $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . '.htaccess',
];
$routerMapped = false;
foreach ($routerFiles as $rf) {
    if (!is_file($rf)) {
        continue;
    }
    $body = (string) file_get_contents($rf);
    foreach ($needlePatterns as $needle) {
        if (str_contains($body, $needle)) {
            $routerMapped = true;
        }
    }
}
hf_assert(!$routerMapped, 'no router/manifest maps to deleted stub routes');

$activeApis = [
    'api/cart/stock-limits.php',
    'api/products/get-products.php',
    'api/products/get-product.php',
    'admin/api/stock/adjust.php',
];
foreach ($activeApis as $rel) {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    hf_assert(is_file($full) && filesize($full) > 20, "active stock/product API still present: {$rel}");
}

$publicStockMutations = glob($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'stock' . DIRECTORY_SEPARATOR . '*.php') ?: [];
hf_assert($publicStockMutations === [], 'no public stock mutation replacement introduced under api/stock/');

$publicOfferApis = glob($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'offers' . DIRECTORY_SEPARATOR . '*.php') ?: [];
hf_assert($publicOfferApis === [], 'no public offers compatibility/replacement endpoint under api/offers/');

foreach (['api/stock/adjust-stock.php', 'api/offers/get-offers.php'] as $rel) {
    $redirectNeedle = $rel;
    $foundRedirect = false;
    foreach ([$root . '/api', $root . '/includes', $root . '/admin'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $fi) {
            if (!$fi instanceof SplFileInfo || strtolower($fi->getExtension()) !== 'php') {
                continue;
            }
            $src = (string) @file_get_contents($fi->getPathname());
            if ($src !== '' && (str_contains($src, 'Location:') || str_contains($src, 'header(')) && str_contains($src, $redirectNeedle)) {
                $foundRedirect = true;
            }
        }
    }
    hf_assert(!$foundRedirect, "no redirect/compatibility handler introduced for {$rel}");
}

$loginSrc = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'login.php');
$helperSrc = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_login_rate_limit.php');

hf_assert(
    str_contains($loginSrc, "require_once __DIR__ . '/../includes/admin_login_rate_limit.php'"),
    'admin login loads rate-limit helper'
);
hf_assert(
    str_contains($loginSrc, 'orange_admin_login_rate_limit_check('),
    'admin login precheck call remains present'
);
hf_assert(
    str_contains($loginSrc, 'orange_admin_login_rate_limit_record_failure('),
    'admin login failure recording remains present'
);
hf_assert(
    !str_contains($helperSrc, 'not wired to login yet'),
    'stale “not wired” comment removed from helper'
);
hf_assert(
    str_contains($helperSrc, 'Wired from admin/login.php'),
    'helper comment documents current login wiring'
);

// Executable throttle contract unchanged: key helpers + defaults still present.
hf_assert(str_contains($helperSrc, 'function orange_admin_login_rate_limit_check'), 'check helper still defined');
hf_assert(str_contains($helperSrc, 'function orange_admin_login_rate_limit_record_failure'), 'failure recorder still defined');
hf_assert(str_contains($helperSrc, "'ORANGE_ADMIN_LOGIN_MAX_ATTEMPTS_USERNAME'"), 'username threshold env key unchanged');
hf_assert(str_contains($helperSrc, "'ORANGE_ADMIN_LOGIN_MAX_ATTEMPTS_IP'"), 'IP threshold env key unchanged');
hf_assert(str_contains($helperSrc, 'orange_admin_login_throttle'), 'storage table name unchanged');
hf_assert(str_contains($helperSrc, 'REMOTE_ADDR'), 'IP authority remains REMOTE_ADDR');

// Only docblock text should differ from a “not wired” claim; ensure no accidental behavior edits in login.php throttle calls order.
$checkPos = strpos($loginSrc, 'orange_admin_login_rate_limit_check(');
$failPos = strpos($loginSrc, 'orange_admin_login_rate_limit_record_failure(');
hf_assert(
    $checkPos !== false && $failPos !== false && $checkPos < $failPos,
    'login throttle call order unchanged (check before failure record)'
);

$schemaSrc = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php');
hf_assert(
    preg_match("/define\\(\\s*'ORANGE_CATALOG_SCHEMA_PHP_REVISION'\\s*,\\s*124\\s*\\)/", $schemaSrc) === 1,
    'schema revision remains 124'
);

echo "\n--- FSR Batch F hygiene ---\n";
echo "PASS={$passes} FAIL={$failures} SKIP={$skips}\n";

exit($failures > 0 ? 1 : 0);
