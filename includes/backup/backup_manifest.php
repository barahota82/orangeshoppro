<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_paths.php';

function orange_backup_sha256_file(string $path): string
{
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException('Cannot hash file: ' . $path);
    }

    return $hash;
}

/**
 * @param list<string> $relativePaths paths relative to package root
 */
function orange_backup_write_checksums(string $packageRoot, array $relativePaths): string
{
    $lines = [];
    sort($relativePaths, SORT_STRING);
    foreach ($relativePaths as $rel) {
        $relNorm = str_replace('\\', '/', ltrim($rel, '/'));
        $abs = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relNorm);
        if (!is_file($abs)) {
            throw new RuntimeException('Checksum target missing: ' . $relNorm);
        }
        $lines[] = orange_backup_sha256_file($abs) . '  ' . $relNorm;
    }
    $content = implode("\n", $lines) . "\n";
    $checksumPath = $packageRoot . DIRECTORY_SEPARATOR . 'checksums.sha256';
    if (file_put_contents($checksumPath, $content) === false) {
        throw new RuntimeException('Cannot write checksums.sha256');
    }

    return $checksumPath;
}

/**
 * @return array{ok:bool, errors:list<string>}
 */
function orange_backup_verify_checksums(string $packageRoot): array
{
    $checksumFile = $packageRoot . DIRECTORY_SEPARATOR . 'checksums.sha256';
    if (!is_file($checksumFile)) {
        return ['ok' => false, 'errors' => ['Missing checksums.sha256']];
    }
    $errors = [];
    $lines = file($checksumFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return ['ok' => false, 'errors' => ['Cannot read checksums.sha256']];
    }
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (!preg_match('/^([a-f0-9]{64})\s{2}(.+)$/', $line, $m)) {
            $errors[] = 'Invalid checksum line: ' . $line;
            continue;
        }
        $expected = $m[1];
        $rel = $m[2];
        $abs = $packageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($abs)) {
            $errors[] = 'Missing file referenced in checksums: ' . $rel;
            continue;
        }
        $actual = orange_backup_sha256_file($abs);
        if (!hash_equals($expected, $actual)) {
            $errors[] = 'Checksum mismatch: ' . $rel;
        }
    }

    return ['ok' => $errors === [], 'errors' => $errors];
}

/**
 * @param array<string, mixed> $manifest
 */
function orange_backup_write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('JSON encode failed for ' . $path);
    }
    if (file_put_contents($path, $json . "\n") === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

function orange_backup_atomic_finalize(string $tempDir, string $finalDir): void
{
    if (is_dir($finalDir)) {
        throw new RuntimeException('Destination already exists: ' . $finalDir);
    }
    if (!@rename($tempDir, $finalDir)) {
        throw new RuntimeException('Cannot finalize package directory rename.');
    }
}

function orange_backup_remove_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            orange_backup_remove_dir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * @param array<string, mixed> $manifest
 * @return list<string> relative paths included in checksum manifest (excluding checksums itself until written)
 */
function orange_backup_collect_package_files(string $packageRoot): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        $full = $fileInfo->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($full, strlen(rtrim($packageRoot, '\\/')))), '/');
        if ($rel === 'checksums.sha256') {
            continue;
        }
        $files[] = $rel;
    }
    sort($files, SORT_STRING);

    return $files;
}
