<?php

declare(strict_types=1);

/**
 * Uploads cutover filesystem helpers (volume identity, reparse-point safety).
 * Test seams via orange_restore_uploads_fs_set_test_seam() for self-tests only.
 *
 * @var array{
 *   volume_identity_provider?:callable(string):string,
 *   reparse_point_detector?:callable(string):?bool
 * }|null
 */
$GLOBALS['orange_restore_uploads_fs_test_seam'] = null;

/**
 * @param array{
 *   volume_identity_provider?:callable(string):string,
 *   reparse_point_detector?:callable(string):?bool
 * }|null $seam
 */
function orange_restore_uploads_fs_set_test_seam(?array $seam): void
{
    $GLOBALS['orange_restore_uploads_fs_test_seam'] = $seam;
}

function orange_restore_uploads_fs_clear_test_seam(): void
{
    $GLOBALS['orange_restore_uploads_fs_test_seam'] = null;
}

/**
 * @return array{volume_identity_provider?:callable(string):string,reparse_point_detector?:callable(string):?bool}|null
 */
function orange_restore_uploads_fs_test_seam(): ?array
{
    $seam = $GLOBALS['orange_restore_uploads_fs_test_seam'] ?? null;

    return is_array($seam) ? $seam : null;
}

/**
 * Resolve absolute path or throw.
 */
function orange_restore_uploads_fs_require_realpath(string $path): string
{
    $real = realpath($path);
    if ($real === false) {
        throw new RuntimeException('Cannot resolve path: ' . $path);
    }

    return $real;
}

/**
 * Opaque volume identity string for a path (fail-closed if unprovable).
 */
function orange_restore_uploads_fs_volume_identity(string $path): string
{
    $seam = orange_restore_uploads_fs_test_seam();
    if (isset($seam['volume_identity_provider']) && is_callable($seam['volume_identity_provider'])) {
        return (string) ($seam['volume_identity_provider'])($path);
    }

    $real = orange_restore_uploads_fs_require_realpath($path);

    if (PHP_OS_FAMILY === 'Windows') {
        return orange_restore_uploads_fs_windows_volume_identity($real);
    }

    $stat = stat($real);
    if ($stat === false || !isset($stat['dev'])) {
        throw new RuntimeException('Cannot determine Unix volume identity for: ' . $path);
    }

    return 'unix-dev:' . (string) $stat['dev'];
}

/**
 * Assert uploads and uploads_next (and parents) share one provable volume identity.
 *
 * @param list<string> $paths
 */
function orange_restore_uploads_fs_assert_same_volume_identity(array $paths): void
{
    if ($paths === []) {
        throw new RuntimeException('Same-volume check requires at least one path.');
    }

    $identities = [];
    foreach ($paths as $path) {
        if (!file_exists($path) && !is_dir($path)) {
            throw new RuntimeException('Same-volume path does not exist: ' . $path);
        }
        $identities[] = orange_restore_uploads_fs_volume_identity($path);
    }

    $unique = array_values(array_unique($identities));
    if (count($unique) !== 1) {
        throw new RuntimeException(
            'Paths are not on the same volume (identities=' . implode(', ', $unique) . ').'
        );
    }
}

function orange_restore_uploads_fs_windows_volume_identity(string $realPath): string
{
    if (!preg_match('/^([A-Za-z]):[\\\\\\/]/', $realPath, $driveMatch)) {
        throw new RuntimeException('Cannot determine Windows drive for volume identity: ' . $realPath);
    }

    $drive = strtoupper($driveMatch[1]);
    $volumeRoot = $drive . ':\\';
    $serial = orange_restore_uploads_fs_windows_query_volume_serial($volumeRoot);
    if ($serial === null || $serial === '') {
        throw new RuntimeException('Cannot prove Windows volume identity for: ' . $realPath);
    }

    $rootStat = stat($volumeRoot);
    $rootInode = is_array($rootStat) && isset($rootStat['ino']) ? (string) $rootStat['ino'] : '0';

    return 'win-vol:' . $drive . ':' . $serial . ':root-ino:' . $rootInode;
}

function orange_restore_uploads_fs_windows_query_volume_serial(string $volumeRoot): ?string
{
    if (!preg_match('/^([A-Za-z]):\\\\$/', $volumeRoot, $m)) {
        return null;
    }
    $drive = strtoupper($m[1]);

    if (function_exists('proc_open')) {
        $cmd = 'cmd /c vol ' . $drive . ':';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $output = (string) $stdout . (string) $stderr;
            if (preg_match('/Volume Serial Number is ([0-9A-Fa-f]{4}-[0-9A-Fa-f]{4})/i', $output, $match)) {
                return strtoupper(str_replace('-', '', $match[1]));
            }
        }
    }

    return null;
}

/**
 * @return bool true if reparse/symlink, false if normal, null if unknown
 */
function orange_restore_uploads_fs_reparse_point_status(string $path): ?bool
{
    $seam = orange_restore_uploads_fs_test_seam();
    if (isset($seam['reparse_point_detector']) && is_callable($seam['reparse_point_detector'])) {
        return ($seam['reparse_point_detector'])($path);
    }

    if (is_link($path)) {
        return true;
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }

    if (!file_exists($path)) {
        return null;
    }

    if (!function_exists('proc_open')) {
        return null;
    }

    $escaped = str_replace("'", "''", $path);
    $command = 'powershell -NoProfile -Command '
        . '"$i=Get-Item -LiteralPath \''
        . $escaped
        . '\' -Force; if ($i.Attributes -band [IO.FileAttributes]::ReparsePoint) { ''1'' } else { ''0'' }"';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return null;
    }
    fclose($pipes[0]);
    $stdout = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    if ($exitCode !== 0) {
        return null;
    }
    if ($stdout === '1') {
        return true;
    }
    if ($stdout === '0') {
        return false;
    }

    return null;
}

function orange_restore_uploads_fs_assert_not_reparse_point(string $path): void
{
    $status = orange_restore_uploads_fs_reparse_point_status($path);
    if ($status === null) {
        throw new RuntimeException('Cannot determine reparse-point status (fail-closed): ' . $path);
    }
    if ($status === true) {
        throw new RuntimeException('Reparse point/symlink/junction blocked: ' . $path);
    }
}

function orange_restore_uploads_fs_assert_path_inside_root(string $path, string $rootReal): void
{
    $pathReal = realpath($path);
    if ($pathReal === false) {
        throw new RuntimeException('Cannot resolve path inside uploads tree: ' . $path);
    }

    orange_restore_uploads_fs_assert_lexical_path_inside_root($pathReal, $rootReal);
}

/**
 * Fail-closed lexical containment check (candidate file may not exist yet).
 */
function orange_restore_uploads_fs_assert_lexical_path_inside_root(string $candidatePath, string $rootReal): void
{
    $rootNorm = strtolower(rtrim(str_replace('\\', '/', $rootReal), '/'));
    $pathNorm = strtolower(rtrim(str_replace('\\', '/', $candidatePath), '/'));
    if ($pathNorm !== $rootNorm && !str_starts_with($pathNorm, $rootNorm . '/')) {
        throw new RuntimeException('Uploads traversal escaped root: ' . $candidatePath);
    }
}

/**
 * @return array{file_count:int,total_size:int,tree_checksum_sha256:string,checksum_lines:list<string>}
 */
function orange_restore_uploads_tree_inventory(string $rootDir): array
{
    if (!is_dir($rootDir)) {
        throw new RuntimeException('Uploads directory does not exist: ' . $rootDir);
    }

    $rootReal = orange_restore_uploads_fs_require_realpath($rootDir);
    orange_restore_uploads_fs_assert_not_reparse_point($rootReal);

    $fileCount = 0;
    $totalSize = 0;
    $checksumLines = [];

    $walk = static function (string $currentDir) use (
        &$walk,
        $rootReal,
        &$fileCount,
        &$totalSize,
        &$checksumLines
    ): void {
        orange_restore_uploads_fs_assert_not_reparse_point($currentDir);
        orange_restore_uploads_fs_assert_path_inside_root($currentDir, $rootReal);

        $entries = scandir($currentDir);
        if ($entries === false) {
            throw new RuntimeException('Cannot scan uploads directory: ' . $currentDir);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $currentDir . DIRECTORY_SEPARATOR . $entry;
            orange_restore_uploads_fs_assert_not_reparse_point($fullPath);
            orange_restore_uploads_fs_assert_path_inside_root($fullPath, $rootReal);

            if (is_link($fullPath)) {
                throw new RuntimeException('Symlink blocked in uploads tree: ' . $fullPath);
            }

            if (is_dir($fullPath)) {
                $walk($fullPath);
                continue;
            }

            if (!is_file($fullPath)) {
                throw new RuntimeException('Unsupported uploads tree entry: ' . $fullPath);
            }

            $relative = ltrim(str_replace('\\', '/', substr($fullPath, strlen($rootReal))), '/');
            if ($relative === '' || str_contains($relative, '..')) {
                throw new RuntimeException('Invalid uploads relative path: ' . $relative);
            }

            $hash = orange_backup_sha256_file($fullPath);
            $checksumLines[] = $hash . '  ' . $relative;
            $fileCount++;
            $totalSize += (int) filesize($fullPath);
        }
    };

    $walk($rootReal);

    sort($checksumLines, SORT_STRING);
    $manifestBody = implode("\n", $checksumLines) . ($checksumLines !== [] ? "\n" : '');
    $treeChecksum = hash('sha256', $manifestBody);

    return [
        'file_count' => $fileCount,
        'total_size' => $totalSize,
        'tree_checksum_sha256' => $treeChecksum,
        'checksum_lines' => $checksumLines,
    ];
}

/**
 * @param list<string> $paths
 */
function orange_restore_uploads_fs_assert_atomic_rename_volume(array $paths): void
{
    orange_restore_uploads_fs_assert_same_volume_identity($paths);
}
