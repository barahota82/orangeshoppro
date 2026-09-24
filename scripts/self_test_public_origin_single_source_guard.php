<?php

declare(strict_types=1);

/**
 * Source-only regression guard: one public-origin authority (SITE_PUBLIC_URL)
 * and no first-party public hostname literals in runtime application code.
 *
 * Does not load config.php, .env.php, or MySQL.
 *
 * Usage: php scripts/self_test_public_origin_single_source_guard.php
 * Optional extra fixture files (negative proof only): additional argv paths.
 */

$root = dirname(__DIR__);
$passes = 0;
$failures = 0;
$extraScanFiles = [];
if (isset($argv) && is_array($argv)) {
    foreach (array_slice($argv, 1) as $arg) {
        if (is_string($arg) && $arg !== '' && is_file($arg)) {
            $extraScanFiles[] = $arg;
        }
    }
}

function pog_assert(bool $condition, string $label): void
{
    global $passes, $failures;
    if ($condition) {
        echo "PASS  {$label}\n";
        $passes++;

        return;
    }

    echo "FAIL  {$label}\n";
    $failures++;
}

function pog_extract_named_function(string $source, string $name): string
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $foundName = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $foundName = $tokens[$j][1];
            }
            break;
        }
        if ($foundName !== $name) {
            continue;
        }

        $text = '';
        $started = false;
        $depth = 0;
        for ($j = $i; $j < $count; $j++) {
            $part = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            $text .= $part;
            if ($part === '{') {
                $started = true;
                $depth++;
            } elseif ($part === '}' && $started) {
                $depth--;
                if ($depth === 0) {
                    return $text;
                }
            }
        }
    }

    return '';
}

function pog_php_files(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (strtolower((string) $file->getExtension()) !== 'php') {
            continue;
        }
        $out[] = $file->getPathname();
    }
    sort($out, SORT_STRING);

    return $out;
}

function pog_js_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        if (strtolower((string) $file->getExtension()) !== 'js') {
            continue;
        }
        $out[] = $file->getPathname();
    }
    sort($out, SORT_STRING);

    return $out;
}

/** Executable PHP text: skip comments/docblocks; keep strings and inline HTML. */
function pog_php_executable_text(string $source): string
{
    $tokens = token_get_all($source);
    $out = '';
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            $out .= $token;
            continue;
        }
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= $token[1];
    }

    return $out;
}

/** Strip JS // and /* * / comments outside quotes. */
function pog_js_executable_text(string $source): string
{
    $len = strlen($source);
    $out = '';
    $i = 0;
    $quote = '';
    $escape = false;
    while ($i < $len) {
        $ch = $source[$i];
        if ($quote !== '') {
            $out .= $ch;
            if ($escape) {
                $escape = false;
            } elseif ($ch === '\\') {
                $escape = true;
            } elseif ($ch === $quote) {
                $quote = '';
            }
            $i++;
            continue;
        }
        if ($ch === '"' || $ch === "'" || $ch === '`') {
            $quote = $ch;
            $out .= $ch;
            $i++;
            continue;
        }
        if ($ch === '/' && $i + 1 < $len && $source[$i + 1] === '/') {
            $i += 2;
            while ($i < $len && $source[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($ch === '/' && $i + 1 < $len && $source[$i + 1] === '*') {
            $i += 2;
            while ($i + 1 < $len && !($source[$i] === '*' && $source[$i + 1] === '/')) {
                $i++;
            }
            $i = min($len, $i + 2);
            continue;
        }
        $out .= $ch;
        $i++;
    }

    return $out;
}

/**
 * @return list<string>
 */
function pog_extract_literal_hosts(string $text): array
{
    if (preg_match_all('#https?://([A-Za-z0-9.-]+)#', $text, $matches) < 1) {
        return [];
    }

    $hosts = [];
    foreach ($matches[1] as $host) {
        $h = strtolower((string) $host);
        if ($h !== '') {
            $hosts[] = $h;
        }
    }

    return $hosts;
}

function pog_php_unquote(string $lit): string
{
    if ($lit === '' || ($lit[0] !== "'" && $lit[0] !== '"')) {
        return '';
    }
    $q = $lit[0];
    $inner = substr($lit, 1, -1);
    if ($q === "'") {
        return str_replace(["\\\\", "\\'"], ['\\', "'"], $inner);
    }

    return stripcslashes($inner);
}

/** Fold simple static PHP concatenations: 'https://' . 'host/path' */
function pog_php_folded_static_text(string $source): string
{
    $tokens = token_get_all($source);
    $n = count($tokens);
    $chunks = [];
    $i = 0;
    while ($i < $n) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $acc = pog_php_unquote($t[1]);
            $j = $i + 1;
            $folded = false;
            while ($j < $n) {
                $u = $tokens[$j];
                if (is_array($u) && in_array($u[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                    continue;
                }
                if ($u !== '.') {
                    break;
                }
                $k = $j + 1;
                while ($k < $n && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $k++;
                }
                if ($k >= $n || !is_array($tokens[$k]) || $tokens[$k][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    break;
                }
                $acc .= pog_php_unquote($tokens[$k][1]);
                $j = $k + 1;
                $folded = true;
            }
            if ($folded) {
                $chunks[] = $acc;
            }
            $i = $j;
            continue;
        }
        $i++;
    }

    return implode("\n", $chunks);
}

function pog_js_unquote(string $lit): string
{
    if ($lit === '' || ($lit[0] !== "'" && $lit[0] !== '"' && $lit[0] !== '`')) {
        return '';
    }
    $inner = substr($lit, 1, -1);

    return str_replace(["\\'", '\\"', '\\\\', '\\n', '\\r', '\\t'], ["'", '"', '\\', "\n", "\r", "\t"], $inner);
}

/** Fold simple static JS concatenations: 'https://' + 'host/path' */
function pog_js_folded_static_text(string $source): string
{
    $text = pog_js_executable_text($source);
    $len = strlen($text);
    $chunks = [];
    $i = 0;
    while ($i < $len) {
        $ch = $text[$i];
        if ($ch !== "'" && $ch !== '"' && $ch !== '`') {
            $i++;
            continue;
        }
        $q = $ch;
        $j = $i + 1;
        $esc = false;
        while ($j < $len) {
            $c = $text[$j];
            if ($esc) {
                $esc = false;
            } elseif ($c === '\\') {
                $esc = true;
            } elseif ($c === $q) {
                break;
            }
            $j++;
        }
        if ($j >= $len) {
            break;
        }
        $acc = pog_js_unquote(substr($text, $i, $j - $i + 1));
        $k = $j + 1;
        $folded = false;
        while ($k < $len) {
            while ($k < $len && ctype_space($text[$k])) {
                $k++;
            }
            if ($k >= $len || $text[$k] !== '+') {
                break;
            }
            $k++;
            while ($k < $len && ctype_space($text[$k])) {
                $k++;
            }
            if ($k >= $len || ($text[$k] !== "'" && $text[$k] !== '"' && $text[$k] !== '`')) {
                break;
            }
            $q2 = $text[$k];
            $m = $k + 1;
            $esc2 = false;
            while ($m < $len) {
                $c2 = $text[$m];
                if ($esc2) {
                    $esc2 = false;
                } elseif ($c2 === '\\') {
                    $esc2 = true;
                } elseif ($c2 === $q2) {
                    break;
                }
                $m++;
            }
            if ($m >= $len) {
                break;
            }
            $acc .= pog_js_unquote(substr($text, $k, $m - $k + 1));
            $k = $m + 1;
            $folded = true;
        }
        if ($folded) {
            $chunks[] = $acc;
        }
        $i = $folded ? $k : ($j + 1);
    }

    return implode("\n", $chunks);
}

/**
 * @return list<string>
 */
function pog_hosts_from_source(string $src, string $kind): array
{
    $text = $kind === 'js' ? pog_js_executable_text($src) : pog_php_executable_text($src);
    $folded = $kind === 'js' ? pog_js_folded_static_text($src) : pog_php_folded_static_text($src);

    return array_values(array_unique(array_merge(
        pog_extract_literal_hosts($text),
        pog_extract_literal_hosts($folded)
    )));
}

function pog_host_is_allowed(string $host): bool
{
    static $providers = [
        'fonts.googleapis.com' => true,
        'fonts.gstatic.com' => true,
        'wa.me' => true,
        'api.myfatoorah.com' => true,
        'apitest.myfatoorah.com' => true,
        'schema.org' => true,
        'translate.googleapis.com' => true,
        'archive.mariadb.org' => true,
    ];
    static $synthetics = [
        'example.com' => true,
        'www.example.com' => true,
        'localhost' => true,
        'your-domain.com' => true,
        'domain.com' => true,
    ];
    static $namespaces = [
        'www.w3.org' => true,
        'schemas.openxmlformats.org' => true,
    ];

    if (isset($providers[$host]) || isset($synthetics[$host]) || isset($namespaces[$host])) {
        return true;
    }
    if (str_ends_with($host, '.test') || str_ends_with($host, '.localhost')) {
        return true;
    }
    if (str_ends_with($host, '.example.com')) {
        return true;
    }

    return false;
}

function pog_rel_path(string $root, string $full): string
{
    $rootNorm = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $fullNorm = str_replace('\\', '/', $full);
    if (str_starts_with($fullNorm, $rootNorm)) {
        return substr($fullNorm, strlen($rootNorm));
    }

    return $fullNorm;
}

$configPath = $root . DIRECTORY_SEPARATOR . 'config.php';
$examplePath = $root . DIRECTORY_SEPARATOR . '.env.example.php';
$config = file_get_contents($configPath);
$example = file_get_contents($examplePath);
if (!is_string($config) || !is_string($example)) {
    fwrite(STDERR, "FAIL: unable to read config.php or .env.example.php\n");
    exit(1);
}

pog_assert(
    str_contains($config, "\$env['SITE_PUBLIC_URL']")
    && str_contains($config, "define('ORANGE_SITE_PUBLIC_ORIGIN'"),
    'config.php still binds SITE_PUBLIC_URL to ORANGE_SITE_PUBLIC_ORIGIN'
);

$originFn = pog_extract_named_function($config, 'orange_site_public_origin');
$absFn = pog_extract_named_function($config, 'storefront_absolute_url');
pog_assert($originFn !== '', 'orange_site_public_origin() is present in config.php');
pog_assert($absFn !== '', 'storefront_absolute_url() is present in config.php');

$forbiddenInResolver = [
    'HTTP_HOST',
    'HTTP_X_FORWARDED_HOST',
    'HTTP_X_FORWARDED_PROTO',
    'SERVER_PORT',
    'localhost',
];
$resolverHasRequestAuthority = false;
foreach ($forbiddenInResolver as $needle) {
    if (str_contains($originFn, $needle)) {
        $resolverHasRequestAuthority = true;
        break;
    }
}
pog_assert(
    !$resolverHasRequestAuthority,
    'orange_site_public_origin() does not use request Host/proto/port or localhost'
);

pog_assert(
    str_contains($originFn, 'RuntimeException')
    && str_contains($originFn, 'throw '),
    'empty SITE_PUBLIC_URL fails closed via throw'
);

pog_assert(
    str_contains($originFn, 'ORANGE_SITE_PUBLIC_ORIGIN')
    && str_contains($originFn, "rtrim(ORANGE_SITE_PUBLIC_ORIGIN, '/')"),
    'configured origin still returns the normalized central constant'
);

pog_assert(
    !preg_match('/https?:\/\//', $originFn),
    'resolver body has no hostname/URL literal'
);

pog_assert(
    str_contains($absFn, 'orange_site_public_origin()'),
    'storefront_absolute_url() still calls orange_site_public_origin()'
);

pog_assert(
    str_contains($example, 'SITE_PUBLIC_URL')
    && str_contains($example, 'https://example.com'),
    '.env.example.php documents SITE_PUBLIC_URL with synthetic example.com'
);

$scanPhpFiles = [];
foreach (['pages', 'admin', 'api', 'includes'] as $relDir) {
    $scanRoot = $root . DIRECTORY_SEPARATOR . $relDir;
    if (is_dir($scanRoot)) {
        foreach (pog_php_files($scanRoot) as $phpFile) {
            $scanPhpFiles[] = $phpFile;
        }
    }
}
foreach ((array) scandir($root) as $name) {
    if (!is_string($name) || $name === '.' || $name === '..') {
        continue;
    }
    if (!preg_match('/\.php$/i', $name)) {
        continue;
    }
    if (in_array(strtolower($name), ['.env.php', '.env.example.php'], true)) {
        continue;
    }
    $full = $root . DIRECTORY_SEPARATOR . $name;
    if (is_file($full)) {
        $scanPhpFiles[] = $full;
    }
}
$scanPhpFiles = array_values(array_unique($scanPhpFiles));
sort($scanPhpFiles, SORT_STRING);

$scanJsFiles = array_merge(
    pog_js_files($root . DIRECTORY_SEPARATOR . 'assets'),
    pog_js_files($root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'assets')
);
$scanJsFiles = array_values(array_unique($scanJsFiles));
sort($scanJsFiles, SORT_STRING);

$hostHits = [];
foreach ($scanPhpFiles as $phpFile) {
    $src = file_get_contents($phpFile);
    if (!is_string($src)) {
        continue;
    }
    if (preg_match('/HTTP_HOST|HTTP_X_FORWARDED_HOST/', $src) === 1) {
        $hostHits[] = pog_rel_path($root, $phpFile);
    }
}
foreach ($scanJsFiles as $jsFile) {
    $src = file_get_contents($jsFile);
    if (!is_string($src)) {
        continue;
    }
    if (preg_match('/HTTP_HOST|HTTP_X_FORWARDED_HOST/', $src) === 1) {
        $hostHits[] = pog_rel_path($root, $jsFile);
    }
}
foreach ($extraScanFiles as $extra) {
    $src = file_get_contents($extra);
    if (!is_string($src)) {
        continue;
    }
    if (preg_match('/HTTP_HOST|HTTP_X_FORWARDED_HOST/', $src) === 1) {
        $hostHits[] = $extra;
    }
}
pog_assert(
    $hostHits === [],
    $hostHits === []
        ? 'no application HTTP_HOST / HTTP_X_FORWARDED_HOST public-origin authority'
        : 'no application HTTP_HOST / HTTP_X_FORWARDED_HOST public-origin authority [' . implode(', ', $hostHits) . ']'
);

$requiredCallers = [
    'includes/header.php' => 'storefront_absolute_url(',
    'pages/home.php' => 'storefront_absolute_url(',
    'pages/product.php' => 'storefront_absolute_url(',
    'manifest.php' => 'storefront_absolute_url(',
    'includes/document_public_token.php' => 'orange_site_public_origin(',
    'admin/api/doc-token/ensure.php' => 'orange_doc_public_absolute_url(',
    'api/auth/request-email-verify.php' => 'orange_site_public_origin(',
    'api/payments/gateway-create.php' => 'storefront_absolute_url(',
];
foreach ($requiredCallers as $rel => $needle) {
    $src = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    pog_assert(
        is_string($src) && str_contains($src, $needle),
        $rel . ' remains on the central origin helper'
    );
}

$literalHits = [];
foreach ($scanPhpFiles as $phpFile) {
    $src = file_get_contents($phpFile);
    if (!is_string($src)) {
        continue;
    }
    foreach (pog_hosts_from_source($src, 'php') as $host) {
        if (!pog_host_is_allowed($host)) {
            $literalHits[] = pog_rel_path($root, $phpFile) . ' host=' . $host;
        }
    }
}
foreach ($scanJsFiles as $jsFile) {
    $src = file_get_contents($jsFile);
    if (!is_string($src)) {
        continue;
    }
    foreach (pog_hosts_from_source($src, 'js') as $host) {
        if (!pog_host_is_allowed($host)) {
            $literalHits[] = pog_rel_path($root, $jsFile) . ' host=' . $host;
        }
    }
}
foreach ($extraScanFiles as $extra) {
    $src = file_get_contents($extra);
    if (!is_string($src)) {
        continue;
    }
    $ext = strtolower((string) pathinfo($extra, PATHINFO_EXTENSION));
    $kind = $ext === 'js' ? 'js' : 'php';
    foreach (pog_hosts_from_source($src, $kind) as $host) {
        if (!pog_host_is_allowed($host)) {
            $literalHits[] = $extra . ' host=' . $host;
        }
    }
}
$literalHits = array_values(array_unique($literalHits));
if ($literalHits !== []) {
    foreach ($literalHits as $hit) {
        echo 'HOST_LITERAL  ' . $hit . "\n";
    }
}
pog_assert(
    $literalHits === [],
    $literalHits === []
        ? 'no disallowed first-party public hostname literal in runtime PHP/JS'
        : 'no disallowed first-party public hostname literal in runtime PHP/JS'
);

if ($failures > 0) {
    fwrite(STDERR, 'RESULT: FAIL (' . $failures . ' failed, ' . $passes . " passed)\n");
    exit(1);
}

fwrite(STDOUT, 'RESULT: PASS (' . $passes . " checks)\n");
exit(0);
