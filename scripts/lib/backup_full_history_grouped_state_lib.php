<?php

declare(strict_types=1);

/**
 * Test-only helpers for Full history completeness + grouped qualification-state loading.
 * Disposable synthetic BackupRoot only — never Production BackupRoot.
 */

require_once dirname(__DIR__, 2) . '/includes/backup/backup_admin.php';
require_once dirname(__DIR__, 2) . '/includes/backup/backup_qualification.php';

function fhgs_temp_backup_root(string $prefix = 'orange_fhgs_'): string
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(4));
    if (!@mkdir($base . DIRECTORY_SEPARATOR . 'snapshots', 0770, true) && !is_dir($base . DIRECTORY_SEPARATOR . 'snapshots')) {
        throw new RuntimeException('Cannot create temp BackupRoot snapshots.');
    }
    @mkdir($base . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw', 0770, true);
    @mkdir($base . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'eg', 0770, true);

    return $base;
}

function fhgs_rm_tree(string $dir): void
{
    $tempParent = realpath(sys_get_temp_dir());
    $resolved = realpath($dir);
    if ($tempParent === false || $resolved === false) {
        return;
    }
    $normTemp = strtolower(str_replace('\\', '/', rtrim($tempParent, '\\/')));
    $normDir = strtolower(str_replace('\\', '/', rtrim($resolved, '\\/')));
    if ($normDir === $normTemp || !str_starts_with($normDir, $normTemp . '/')
        || (!str_contains($normDir, '/orange_fhgs_') && !str_contains($normDir, '/orange_s4b_'))) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $path = $file->getPathname();
        $file->isDir() ? @rmdir($path) : @unlink($path);
    }
    @rmdir($resolved);
}

/**
 * @return array{path:string,id:string}
 */
function fhgs_mk_full_package(
    string $backupRoot,
    string $packageId,
    string $tag = 'x',
    string $backend = 'mysqldump',
    bool $healthy = true,
    bool $withManifest = true,
    bool $validManifest = true,
    int $payloadBytes = 64
): array {
    $path = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $packageId;
    if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create package dir: ' . $packageId);
    }
    $dump = $path . DIRECTORY_SEPARATOR . 'dump.sql.gz';
    $up = $path . DIRECTORY_SEPARATOR . 'uploads.zip';
    file_put_contents($dump, "\x1f\x8b" . str_repeat($tag, max(8, $payloadBytes)));
    file_put_contents($up, 'PK' . str_repeat('z', max(8, (int) ($payloadBytes / 2))));
    orange_backup_write_checksums($path, ['dump.sql.gz', 'uploads.zip']);
    if ($withManifest) {
        $manifest = $validManifest ? [
            'package_type' => 'full_disaster',
            'package_version' => '1.0',
            'schema_revision' => 124,
            'generated_at' => gmdate('c'),
            'backup_status' => 'success',
            'export_backend' => $backend,
            'dump_file' => 'dump.sql.gz',
            'uploads_file' => 'uploads.zip',
            'dump_sha256' => orange_backup_sha256_file($dump),
            'uploads_sha256' => orange_backup_sha256_file($up),
            'dump_size_bytes' => filesize($dump) ?: 0,
            'uploads_size_bytes' => filesize($up) ?: 0,
            'table_count' => 10,
            'approx_total_rows' => 100,
        ] : '{not-json';
        if (is_array($manifest)) {
            file_put_contents(
                $path . DIRECTORY_SEPARATOR . 'manifest.json',
                json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        } else {
            file_put_contents($path . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
        }
    }
    file_put_contents($path . DIRECTORY_SEPARATOR . 'health.json', json_encode([
        'package_status' => $healthy ? 'healthy' : 'failed',
        'generated_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return ['path' => $path, 'id' => $packageId];
}

/**
 * @return list<string> newest-first package ids
 */
function fhgs_mk_n_full(string $backupRoot, int $n, string $day = '2026-07-15'): array
{
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        // Spread across hours/minutes; allow same calendar day.
        $h = intdiv($i, 60) % 24;
        $m = $i % 60;
        $s = ($i * 3) % 60;
        $id = sprintf('%s_%02d%02d%02d', $day, $h, $m, $s);
        // Ensure uniqueness if collision
        while (in_array($id, $ids, true)) {
            $s = ($s + 1) % 60;
            $id = sprintf('%s_%02d%02d%02d', $day, $h, $m, $s);
        }
        $backend = ($i % 2 === 0) ? 'mysqldump' : 'manual';
        fhgs_mk_full_package($backupRoot, $id, chr(97 + ($i % 26)), $backend);
        $ids[] = $id;
    }
    rsort($ids, SORT_STRING);

    return $ids;
}

/**
 * Client-side Last 5 slice — mirrors Backup Center RECENT_LIMIT behaviour.
 *
 * @param list<array<string,mixed>> $packages
 * @return list<array<string,mixed>>
 */
function fhgs_client_last5(array $packages): array
{
    return array_slice($packages, 0, 5);
}

/**
 * @param list<array{package_type?:string,package_id?:string,country_code?:string}> $items
 * @return list<list<array{package_type?:string,package_id?:string,country_code?:string}>>
 */
function fhgs_chunk_cohorts(array $items, int $size = 5): array
{
    if ($size < 1) {
        $size = 5;
    }
    $out = [];
    for ($i = 0; $i < count($items); $i += $size) {
        $out[] = array_slice($items, $i, $size);
    }

    return $out;
}
