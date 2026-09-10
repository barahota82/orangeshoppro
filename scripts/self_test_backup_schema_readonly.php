<?php

declare(strict_types=1);

/**
 * Static certification for scheduled full-backup schema isolation.
 *
 * This test only reads source files. It never loads application code, opens a
 * database connection, starts a backup, executes a command, or uses a network.
 *
 * Usage: php scripts/self_test_backup_schema_readonly.php
 */

$root = dirname(__DIR__);
$passes = 0;
$failures = 0;

function bsr_assert(bool $condition, string $label): void
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

function bsr_extract_named_function(string $source, string $name): string
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

/**
 * @return array{calls:array<string,list<string>>,locations:array<string,string>}
 */
function bsr_parse_php_calls(string $root, array $relativeFiles): array
{
    $calls = [];
    $locations = [];

    foreach ($relativeFiles as $relative) {
        $source = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        if (!is_string($source)) {
            continue;
        }

        $tokens = token_get_all($source);
        $top = '@top:' . $relative;
        $calls[$top] ??= [];
        $locations[$top] = $relative;
        $current = $top;
        $functionStack = [];
        $braceDepth = 0;
        $pendingFunction = null;
        $expectFunctionName = false;

        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if ($id === T_FUNCTION) {
                    $expectFunctionName = true;
                    $pendingFunction = null;
                    continue;
                }
                if ($expectFunctionName && $id === T_STRING) {
                    $pendingFunction = $text;
                    $expectFunctionName = false;
                    $calls[$pendingFunction] ??= [];
                    $locations[$pendingFunction] = $relative;
                    continue;
                }
                if ($expectFunctionName && $id === T_VARIABLE) {
                    $pendingFunction = '@closure:' . $relative . ':' . $index;
                    $expectFunctionName = false;
                    $calls[$pendingFunction] ??= [];
                    $locations[$pendingFunction] = $relative;
                    continue;
                }
                if ($id !== T_STRING) {
                    continue;
                }

                $next = null;
                for ($j = $index + 1, $count = count($tokens); $j < $count; $j++) {
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $next = $tokens[$j];
                    break;
                }
                if ($next === '(') {
                    $calls[$current][] = $text;
                }
                continue;
            }

            if ($token === '{') {
                $braceDepth++;
                if ($pendingFunction !== null) {
                    $functionStack[] = ['name' => $current, 'depth' => $braceDepth];
                    $current = $pendingFunction;
                    $pendingFunction = null;
                }
            } elseif ($token === '}') {
                if ($functionStack !== [] && $functionStack[array_key_last($functionStack)]['depth'] === $braceDepth) {
                    $frame = array_pop($functionStack);
                    $current = $frame['name'];
                }
                $braceDepth--;
            }
        }
    }

    foreach ($calls as &$functionCalls) {
        $functionCalls = array_values(array_unique($functionCalls));
    }
    unset($functionCalls);

    return ['calls' => $calls, 'locations' => $locations];
}

/**
 * @return array<string,list<string>>
 */
function bsr_reachable_paths(array $calls, array $roots): array
{
    $paths = [];
    $queue = [];
    foreach ($roots as $root) {
        $paths[$root] = [$root];
        $queue[] = $root;
    }

    while ($queue !== []) {
        $caller = array_shift($queue);
        foreach ($calls[$caller] ?? [] as $callee) {
            if (isset($paths[$callee])) {
                continue;
            }
            $paths[$callee] = array_merge($paths[$caller], [$callee]);
            if (isset($calls[$callee])) {
                $queue[] = $callee;
            }
        }
    }

    return $paths;
}

function bsr_is_forbidden_call(string $call): bool
{
    return preg_match(
        '/^(?:orange_catalog_ensure_schema(?:_core)?|orange_schema_check_and_bootstrap|'
        . 'orange_run_migrations|orange_schema_run_(?:pending_migrations|numbered_sql_chain)|'
        . 'orange_db_id_renumber[a-z0-9_]*|orange_[a-z0-9_]*runtime_hook[a-z0-9_]*)$/i',
        $call
    ) === 1;
}

$productionFiles = [
    'includes/backup/backup_environment.php',
    'includes/backup/backup_runner.php',
    'scripts/backup/backup_metadata.php',
    'scripts/backup/finalize_full_backup.php',
];
$scheduledEntrypoints = [
    'scripts/backup/run_full_backup.php',
    'scripts/backup/backup_metadata.php',
    'scripts/backup/finalize_full_backup.php',
];

$phpFiles = [];
foreach ([
    $root . '/includes/backup',
    $root . '/scripts/backup',
] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
        if (str_contains($relative, '/self_test_') || str_contains($relative, '/country_production/')) {
            continue;
        }
        $phpFiles[] = $relative;
    }
}
$phpFiles = array_values(array_unique(array_merge($phpFiles, $scheduledEntrypoints)));

$graph = bsr_parse_php_calls($root, $phpFiles);
$calls = $graph['calls'];

foreach ($productionFiles as $relative) {
    $directCalls = $calls['@top:' . $relative] ?? [];
    foreach ($calls as $function => $functionCalls) {
        if (($graph['locations'][$function] ?? '') === $relative && !str_starts_with($function, '@top:')) {
            $directCalls = array_merge($directCalls, $functionCalls);
        }
    }
    $forbidden = array_values(array_filter(array_unique($directCalls), 'bsr_is_forbidden_call'));
    bsr_assert($forbidden === [], $relative . ' contains zero forbidden calls'
        . ($forbidden === [] ? '' : ': ' . implode(', ', $forbidden)));
}

$phpPath = bsr_reachable_paths($calls, ['@top:scripts/backup/run_full_backup.php']);
$phpForbidden = [];
foreach ($phpPath as $call => $path) {
    if (bsr_is_forbidden_call($call)) {
        $phpForbidden[] = implode(' -> ', $path);
    }
}
bsr_assert($phpForbidden === [], 'run_full_backup.php -> backup_runner backends reaches zero schema/migration calls'
    . ($phpForbidden === [] ? '' : ': ' . implode(' | ', $phpForbidden)));

$powershell = (string) file_get_contents($root . '/scripts/backup/orange_backup.ps1');
bsr_assert(
    str_contains($powershell, 'backup_metadata.php') && str_contains($powershell, 'finalize_full_backup.php'),
    'orange_backup.ps1 traces through metadata and finalization entrypoints'
);

$psRoots = [
    '@top:scripts/backup/backup_metadata.php',
    '@top:scripts/backup/finalize_full_backup.php',
];
$psPath = bsr_reachable_paths($calls, $psRoots);
$psForbidden = [];
foreach ($psPath as $call => $path) {
    if (bsr_is_forbidden_call($call)) {
        $psForbidden[] = implode(' -> ', $path);
    }
}
bsr_assert($psForbidden === [], 'orange_backup.ps1 metadata/finalize call graph reaches zero schema/migration calls'
    . ($psForbidden === [] ? '' : ': ' . implode(' | ', $psForbidden)));

foreach ([
    'scripts/backup/backup_metadata.php',
    'scripts/backup/finalize_full_backup.php',
] as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    bsr_assert(
        preg_match('/require(?:_once)?[^;]*catalog_schema\.php/i', $source) !== 1
            && !str_contains($source, 'orange_catalog_ensure_schema('),
        $relative . ' does not load or invoke schema migration code'
    );
    bsr_assert(
        preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b\s+/i', $source) !== 1,
        $relative . ' metadata path contains no DDL/DML statement'
    );
}

$metadataSource = (string) file_get_contents($root . '/scripts/backup/backup_metadata.php');
$finalizeSource = (string) file_get_contents($root . '/scripts/backup/finalize_full_backup.php');
$revisionFunction = bsr_extract_named_function(
    $metadataSource,
    'orange_backup_metadata_code_schema_revision'
);
bsr_assert($revisionFunction !== '', 'metadata revision reader function can be extracted for execution');
if ($revisionFunction !== '') {
    eval($revisionFunction);
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange-backup-revision-' . bin2hex(random_bytes(6));
    $fixtureIncludes = $fixtureRoot . DIRECTORY_SEPARATOR . 'includes';
    mkdir($fixtureIncludes, 0777, true);
    try {
        file_put_contents(
            $fixtureIncludes . DIRECTORY_SEPARATOR . 'catalog_schema.php',
            "<?php\ndefine('ORANGE_CATALOG_SCHEMA_PHP_REVISION', 124);\n"
        );
        bsr_assert(
            orange_backup_metadata_code_schema_revision($fixtureRoot) === 124,
            'metadata revision reader extracts the actual define() form as 124'
        );
        file_put_contents(
            $fixtureIncludes . DIRECTORY_SEPARATOR . 'catalog_schema.php',
            "<?php\nconst ORANGE_CATALOG_SCHEMA_PHP_REVISION = 125;\n"
        );
        bsr_assert(
            orange_backup_metadata_code_schema_revision($fixtureRoot) === 125,
            'metadata revision reader retains const fallback compatibility'
        );
    } finally {
        @unlink($fixtureIncludes . DIRECTORY_SEPARATOR . 'catalog_schema.php');
        @rmdir($fixtureIncludes);
        @rmdir($fixtureRoot);
    }
}
bsr_assert(
    str_contains($metadataSource, 'INFORMATION_SCHEMA.TABLES')
        && str_contains($metadataSource, 'orange_backup_collect_safe_metadata'),
    'backup metadata remains SELECT/INFORMATION_SCHEMA based and existing-table tolerant'
);
bsr_assert(
    str_contains($finalizeSource, 'INFORMATION_SCHEMA.TABLES')
        && str_contains($finalizeSource, 'orange_backup_collect_safe_metadata'),
    'finalizer metadata remains SELECT/INFORMATION_SCHEMA based and existing-table tolerant'
);

$runner = (string) file_get_contents($root . '/includes/backup/backup_runner.php');
bsr_assert(
    str_contains($runner, "'powershell' => orange_backup_run_via_powershell")
        && str_contains($runner, "'php_mysqldump' => orange_backup_run_via_php_mysqldump")
        && str_contains($runner, "'php_pdo' => orange_backup_run_via_pdo"),
    'run_full_backup.php retains all three backend selections'
);
bsr_assert(
    str_contains($runner, 'orange_backup_gzip_file(')
        && str_contains($runner, 'orange_backup_zip_directory(')
        && str_contains($runner, 'orange_backup_full_finalize_workspace(')
        && str_contains($runner, 'orange_backup_verify_full_package(')
        && str_contains($runner, 'orange_backup_apply_retention('),
    'PHP backends retain dump packaging, finalize, verification, and retention'
);
bsr_assert(
    str_contains($powershell, 'mysqldump')
        && str_contains($powershell, 'Compress-FileToGzip')
        && str_contains($powershell, 'Compress-Archive')
        && str_contains($powershell, 'finalize_full_backup.php')
        && str_contains($powershell, 'Invoke-RetentionCleanup'),
    'PowerShell backend retains dump, packaging, finalization, and retention'
);

$compareRoot = '';
foreach ($_SERVER['argv'] ?? [] as $argument) {
    if (str_starts_with($argument, '--compare-root=')) {
        $compareRoot = substr($argument, strlen('--compare-root='));
    }
}
if ($compareRoot !== '') {
    $baselineFile = $root . '/scripts/emergency_patch_r2_baseline_integrity.json';
    $baseline = json_decode((string) file_get_contents($baselineFile), true);
    $baselineRows = is_array($baseline['files'] ?? null) ? $baseline['files'] : [];
    $baselinePaths = [];
    $different = [];
    $missing = [];
    foreach ($baselineRows as $row) {
        $relative = str_replace('\\', '/', (string) ($row['path'] ?? ''));
        if ($relative === '') {
            continue;
        }
        $baselinePaths[$relative] = true;
        $candidate = $root . '/' . $relative;
        $comparison = rtrim($compareRoot, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($candidate) || !is_file($comparison)) {
            $missing[] = $relative;
            continue;
        }
        $candidateHash = hash_file('sha256', $candidate);
        $comparisonHash = hash_file('sha256', $comparison);
        if ($candidateHash !== $comparisonHash) {
            $different[$relative] = [
                'comparison' => $comparisonHash,
                'candidate' => $candidateHash,
            ];
        }
    }

    $extra = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        $relative = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
        if ($relative === 'scripts/emergency_patch_r2_baseline_integrity.json' || isset($baselinePaths[$relative])) {
            continue;
        }
        $extra[] = $relative;
    }
    sort($extra);

    $expectedDifferent = [
        'includes/backup/backup_environment.php',
        'includes/backup/backup_runner.php',
        'scripts/backup/backup_metadata.php',
        'scripts/backup/finalize_full_backup.php',
    ];
    $actualDifferent = array_keys($different);
    sort($expectedDifferent);
    sort($actualDifferent);
    bsr_assert($missing === [], 'whole-workspace comparison has no missing baseline files'
        . ($missing === [] ? '' : ': ' . implode(', ', $missing)));
    bsr_assert(
        $actualDifferent === $expectedDifferent,
        'only four authorized production files differ from comparison root'
            . ($actualDifferent === $expectedDifferent ? '' : ': ' . implode(', ', $actualDifferent))
    );
    bsr_assert(
        $extra === ['scripts/self_test_backup_schema_readonly.php'],
        'only the authorized static test is newly added'
            . ($extra === ['scripts/self_test_backup_schema_readonly.php'] ? '' : ': ' . implode(', ', $extra))
    );
    foreach ($different as $relative => $hashes) {
        echo "HASH  {$relative} D={$hashes['comparison']} C={$hashes['candidate']}\n";
    }
    echo 'HASH  scripts/self_test_backup_schema_readonly.php D=<new> C='
        . hash_file('sha256', __FILE__)
        . "\n";
}

echo "\n--- summary ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
exit($failures > 0 ? 1 : 0);
