<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_manifest.php';
require_once __DIR__ . '/backup_full.php';

const ORANGE_BACKUP_RETENTION_ENV_DAYS = 'ORANGE_BACKUP_RETENTION_DAYS';
const ORANGE_BACKUP_RETENTION_DEFAULT_DAYS = 30;
const ORANGE_BACKUP_RETENTION_FAMILY_FULL = 'full_disaster';
const ORANGE_BACKUP_RETENTION_FAMILY_CRP = 'country_recovery';

function orange_backup_retention_days(array $env): int
{
    return max(1, (int) ($env[ORANGE_BACKUP_RETENTION_ENV_DAYS] ?? ORANGE_BACKUP_RETENTION_DEFAULT_DAYS));
}

function orange_backup_retention_is_temp_dir_name(string $name): bool
{
    return str_starts_with($name, '._work_')
        || str_starts_with($name, '.tmp_')
        || str_starts_with($name, '.tmp');
}

function orange_backup_retention_is_finalized_dir_name(string $name): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}_\d{6}$/', $name);
}

function orange_backup_retention_dir_age_seconds(string $name, int $mtime): int
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{6})$/', $name, $matches)) {
        $datePart = $matches[1];
        $timePart = $matches[2];
        $formatted = $datePart . ' '
            . substr($timePart, 0, 2) . ':'
            . substr($timePart, 2, 2) . ':'
            . substr($timePart, 4, 2);
        $parsed = strtotime($formatted);
        if ($parsed !== false) {
            return max(0, time() - $parsed);
        }
    }

    return max(0, time() - $mtime);
}

function orange_backup_retention_age_exceeds(int $ageSeconds, int $retentionDays): bool
{
    return $ageSeconds > ($retentionDays * 86400);
}

function orange_backup_retention_path_within_root(string $backupRoot, string $targetPath): bool
{
    $rootReal = realpath($backupRoot);
    if ($rootReal === false) {
        return false;
    }
    $rootNorm = strtolower(rtrim(str_replace('\\', '/', $rootReal), '/'));

    if (is_link($targetPath)) {
        return false;
    }

    $targetReal = realpath($targetPath);
    if ($targetReal === false) {
        $candidate = strtolower(rtrim(str_replace('\\', '/', $targetPath), '/'));

        return str_starts_with($candidate, $rootNorm . '/');
    }

    $targetNorm = strtolower(rtrim(str_replace('\\', '/', $targetReal), '/'));

    return str_starts_with($targetNorm, $rootNorm . '/');
}

/**
 * @return list<array{name:string,path:string,mtime:int,age_seconds:int}>
 */
function orange_backup_retention_list_finalized_dirs(string $containerDir): array
{
    if (!is_dir($containerDir)) {
        return [];
    }

    $dirs = [];
    foreach (scandir($containerDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || orange_backup_retention_is_temp_dir_name($entry)) {
            continue;
        }
        if (!orange_backup_retention_is_finalized_dir_name($entry)) {
            continue;
        }
        $full = $containerDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($full) || is_link($full)) {
            continue;
        }
        $mtime = filemtime($full) ?: time();
        $dirs[] = [
            'name' => $entry,
            'path' => $full,
            'mtime' => $mtime,
            'age_seconds' => orange_backup_retention_dir_age_seconds($entry, $mtime),
        ];
    }

    usort($dirs, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

    return $dirs;
}

function orange_backup_retention_full_is_healthy(string $packageDir): bool
{
    if (!is_dir($packageDir)) {
        return false;
    }
    $healthPath = $packageDir . DIRECTORY_SEPARATOR . ORANGE_BACKUP_HEALTH_FILE;
    if (!is_file($healthPath)) {
        return false;
    }
    $health = json_decode((string) file_get_contents($healthPath), true);
    if (!is_array($health) || ($health['package_status'] ?? '') !== 'healthy') {
        return false;
    }

    $verify = orange_backup_verify_full_package($packageDir);

    return $verify['ok'];
}

function orange_backup_retention_crp_is_healthy(string $packageDir): bool
{
    if (!is_dir($packageDir)) {
        return false;
    }
    require_once __DIR__ . '/backup_validate.php';
    $healthPath = $packageDir . DIRECTORY_SEPARATOR . 'health.json';
    if (!is_file($healthPath)) {
        return false;
    }
    $health = json_decode((string) file_get_contents($healthPath), true);
    if (!is_array($health) || ($health['package_status'] ?? '') !== 'healthy') {
        return false;
    }

    $verify = orange_country_export_verify_package($packageDir);

    return $verify['ok'];
}

function orange_backup_retention_find_newest_healthy_name(string $containerDir, string $family): ?string
{
    foreach (orange_backup_retention_list_finalized_dirs($containerDir) as $dir) {
        $healthy = $family === ORANGE_BACKUP_RETENTION_FAMILY_CRP
            ? orange_backup_retention_crp_is_healthy($dir['path'])
            : orange_backup_retention_full_is_healthy($dir['path']);
        if ($healthy) {
            return $dir['name'];
        }
    }

    return null;
}

/**
 * @param callable(string,string=):void|null $logger
 * @return array{
 *   kept:list<array{name:string,path:string,reason:string}>,
 *   deleted:list<array{name:string,path:string,reason:string}>,
 *   errors:list<string>
 * }
 */
function orange_backup_retention_apply(
    string $backupRoot,
    string $containerDir,
    string $family,
    ?string $currentPackageName,
    int $retentionDays,
    ?callable $logger = null
): array {
    $logger ??= static function (string $message, string $level = 'INFO'): void {
    };

    $kept = [];
    $deleted = [];
    $errors = [];
    $allDirs = orange_backup_retention_list_finalized_dirs($containerDir);
    if ($allDirs === []) {
        return ['kept' => $kept, 'deleted' => $deleted, 'errors' => $errors];
    }

    $newestHealthy = orange_backup_retention_find_newest_healthy_name($containerDir, $family);
    $protected = [];

    if ($currentPackageName !== null && $currentPackageName !== '') {
        $protected[$currentPackageName] = 'current_run';
    }
    if ($newestHealthy !== null) {
        $protected[$newestHealthy] = 'newest_verified_healthy';
    }

    foreach ($allDirs as $dir) {
        $name = $dir['name'];
        $path = $dir['path'];

        if (isset($protected[$name])) {
            $kept[] = ['name' => $name, 'path' => $path, 'reason' => $protected[$name]];
            $logger('Retention keep name=' . $name . ' reason=' . $protected[$name]);
            continue;
        }

        if (!orange_backup_retention_age_exceeds($dir['age_seconds'], $retentionDays)) {
            $kept[] = ['name' => $name, 'path' => $path, 'reason' => 'within_retention_window'];
            $logger('Retention keep name=' . $name . ' reason=within_retention_window age_days=' . round($dir['age_seconds'] / 86400, 2));
            continue;
        }

        $isHealthy = $family === ORANGE_BACKUP_RETENTION_FAMILY_CRP
            ? orange_backup_retention_crp_is_healthy($path)
            : orange_backup_retention_full_is_healthy($path);
        if ($isHealthy && $newestHealthy === null) {
            $kept[] = ['name' => $name, 'path' => $path, 'reason' => 'verified_healthy_no_replacement'];
            $logger('Retention keep name=' . $name . ' reason=verified_healthy_no_replacement');
            continue;
        }

        if (!orange_backup_retention_path_within_root($backupRoot, $path)) {
            $errors[] = 'Retention blocked path outside BackupRoot: ' . $path;
            $logger('Retention blocked path outside BackupRoot: ' . $path, 'ERROR');
            continue;
        }

        orange_backup_remove_dir($path);
        if (is_dir($path)) {
            $errors[] = 'Retention failed to delete: ' . $path;
            $logger('Retention failed to delete: ' . $path, 'ERROR');
            continue;
        }

        $deleted[] = ['name' => $name, 'path' => $path, 'reason' => 'expired_older_than_' . $retentionDays . '_days'];
        $logger('Retention deleted name=' . $name . ' reason=expired_older_than_' . $retentionDays . '_days');
    }

    return ['kept' => $kept, 'deleted' => $deleted, 'errors' => $errors];
}

/**
 * @param callable(string,string=):void|null $logger
 * @return array{kept:list<array<string,mixed>>,deleted:list<array<string,mixed>>,errors:list<string>}
 */
function orange_backup_retention_apply_full_snapshots(
    string $backupRoot,
    string $snapshotsDir,
    ?string $currentSnapshotName,
    int $retentionDays,
    ?callable $logger = null
): array {
    return orange_backup_retention_apply(
        $backupRoot,
        $snapshotsDir,
        ORANGE_BACKUP_RETENTION_FAMILY_FULL,
        $currentSnapshotName,
        $retentionDays,
        $logger
    );
}

/**
 * @param callable(string,string=):void|null $logger
 * @return array{kept:list<array<string,mixed>>,deleted:list<array<string,mixed>>,errors:list<string>}
 */
function orange_backup_retention_apply_country_packages(
    string $backupRoot,
    string $countryCode,
    ?string $currentPackageName,
    int $retentionDays,
    ?callable $logger = null
): array {
    $countryPackagesDir = orange_backup_path_inside_root(
        $backupRoot,
        'country_packages' . DIRECTORY_SEPARATOR . $countryCode
    );

    return orange_backup_retention_apply(
        $backupRoot,
        $countryPackagesDir,
        ORANGE_BACKUP_RETENTION_FAMILY_CRP,
        $currentPackageName,
        $retentionDays,
        $logger
    );
}

/**
 * @return list<string>
 */
function orange_backup_retention_list_country_codes(string $backupRoot): array
{
    $countryRoot = orange_backup_path_inside_root($backupRoot, 'country_packages');
    if (!is_dir($countryRoot)) {
        return [];
    }

    $codes = [];
    foreach (scandir($countryRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || orange_backup_retention_is_temp_dir_name($entry)) {
            continue;
        }
        $full = $countryRoot . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($full) && !is_link($full)) {
            $codes[] = $entry;
        }
    }

    sort($codes);

    return $codes;
}

/**
 * @param array<string, ?string> $currentPackagesByCountry country_code => package_name
 * @param callable(string,string=):void|null $logger
 * @return array<string, array{kept:list<array<string,mixed>>,deleted:list<array<string,mixed>>,errors:list<string>}>
 */
function orange_backup_retention_apply_all_country_packages(
    string $backupRoot,
    array $currentPackagesByCountry,
    int $retentionDays,
    ?callable $logger = null
): array {
    $results = [];
    $countryCodes = array_unique(array_merge(
        orange_backup_retention_list_country_codes($backupRoot),
        array_keys($currentPackagesByCountry)
    ));
    sort($countryCodes);

    foreach ($countryCodes as $countryCode) {
        $current = $currentPackagesByCountry[$countryCode] ?? null;
        $results[$countryCode] = orange_backup_retention_apply_country_packages(
            $backupRoot,
            $countryCode,
            $current,
            $retentionDays,
            $logger
        );
    }

    return $results;
}
