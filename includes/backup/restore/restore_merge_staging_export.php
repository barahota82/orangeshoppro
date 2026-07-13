<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_sql_safety.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/../backup_runner.php';
require_once __DIR__ . '/../backup_pdo_export.php';
require_once __DIR__ . '/restore_validation_adapter.php';

/**
 * Export validated staging database to merge artifact (php_pdo format, no production writes).
 *
 * @param array{
 *   project_root:string,
 *   work_root:string,
 *   job_id:string,
 *   env:array<string,mixed>,
 *   staging_pdo_override?:?PDO
 * } $options
 * @return array<string, mixed>
 */
function orange_restore_merge_staging_export_run(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Staging export is CLI-only.');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $workRoot = (string) ($options['work_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    /** @var array<string, mixed> $env */
    $env = is_array($options['env'] ?? null) ? $options['env'] : [];

    if ($projectRoot === '' || $workRoot === '' || $jobId === '') {
        throw new InvalidArgumentException('project_root, work_root, and job_id are required.');
    }

    $stagingDb = orange_restore_staging_db_name($env, $projectRoot);
    $productionDb = orange_restore_production_db_name($projectRoot);
    $stagingPdo = $options['staging_pdo_override'] ?? null;
    $pdo = $stagingPdo instanceof PDO
        ? $stagingPdo
        : orange_restore_connect_staging_pdo($projectRoot, $env);
    orange_restore_staging_assert_safe_target($pdo, $stagingDb);
    orange_restore_staging_assert_no_production_privileges($pdo, $stagingDb, $productionDb);

    $jobDir = orange_restore_job_directory($workRoot, $jobId);
    $rawSqlPath = $jobDir . DIRECTORY_SEPARATOR . 'merge_db_export.sql';
    $gzipPath = orange_restore_merge_db_export_gzip_path($workRoot, $jobId);
    $manifestPath = orange_restore_merge_db_export_manifest_path($workRoot, $jobId);

    if (is_file($rawSqlPath)) {
        @unlink($rawSqlPath);
    }
    if (is_file($gzipPath)) {
        @unlink($gzipPath);
    }

    $startedAt = microtime(true);
    $export = orange_backup_pdo_export_database($pdo, $stagingDb, $rawSqlPath);
    orange_backup_gzip_file($rawSqlPath, $gzipPath);
    @unlink($rawSqlPath);

    if (!is_file($gzipPath)) {
        throw new RuntimeException('Staging export gzip artifact missing after export.');
    }

    $verify = orange_restore_merge_staging_export_verify_gzip(
        $gzipPath,
        $productionDb,
        $stagingDb,
        (int) ($export['table_count'] ?? 0)
    );
    if (!$verify['ok']) {
        throw new RuntimeException('Staging export verification failed: ' . (string) ($verify['error'] ?? ''));
    }

    $checksum = orange_restore_validation_adapter_file_checksum($gzipPath);
    $durationSeconds = (int) round(microtime(true) - $startedAt);
    $manifest = [
        'export_backend' => 'php_pdo',
        'staging_db' => $stagingDb,
        'production_db' => $productionDb,
        'gzip_path' => $gzipPath,
        'checksum_sha256' => $checksum,
        'table_count' => (int) ($export['table_count'] ?? 0),
        'row_count' => (int) ($export['row_count'] ?? 0),
        'exported_at' => gmdate('c'),
        'duration_seconds' => $durationSeconds,
        'warnings' => $export['warnings'] ?? [],
        'maintenance_notes' => $export['maintenance_notes'] ?? [],
        'production_writes' => false,
    ];
    orange_backup_write_json($manifestPath, $manifest);

    return [
        'ok' => true,
        'gzip_path' => $gzipPath,
        'manifest_path' => $manifestPath,
        'checksum_sha256' => $checksum,
        'table_count' => (int) ($export['table_count'] ?? 0),
        'row_count' => (int) ($export['row_count'] ?? 0),
        'duration_seconds' => $durationSeconds,
        'production_writes' => false,
    ];
}

/**
 * Verify merge export artifact before any production mutation.
 *
 * @return array{ok:bool,error:?string,hits:list<string>}
 */
function orange_restore_merge_staging_export_verify_gzip(
    string $gzipPath,
    string $targetDb,
    string $forbiddenOtherDb,
    int $tableCount
): array {
    if (!is_file($gzipPath)) {
        return ['ok' => false, 'error' => 'Merge export gzip missing.', 'hits' => []];
    }

    $scan = orange_restore_sql_scan_gzip_forbidden_patterns($gzipPath, $targetDb, $forbiddenOtherDb);
    if (!$scan['ok']) {
        return ['ok' => false, 'error' => (string) ($scan['error'] ?? 'Forbidden SQL pattern in export.'), 'hits' => $scan['hits'] ?? []];
    }

    $crossScan = orange_restore_merge_staging_export_scan_cross_schema($gzipPath, $targetDb, $forbiddenOtherDb);
    if (!$crossScan['ok']) {
        return $crossScan;
    }

    $tmpSql = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_merge_export_verify_' . bin2hex(random_bytes(4)) . '.sql';
    try {
        $in = gzopen($gzipPath, 'rb');
        if ($in === false) {
            return ['ok' => false, 'error' => 'Cannot open export gzip for format validation.', 'hits' => []];
        }
        $out = fopen($tmpSql, 'wb');
        if ($out === false) {
            gzclose($in);

            return ['ok' => false, 'error' => 'Cannot create temporary SQL file for export validation.', 'hits' => []];
        }
        while (!gzeof($in)) {
            $chunk = gzread($in, 65536);
            if ($chunk === false) {
                fclose($out);
                gzclose($in);

                return ['ok' => false, 'error' => 'Corrupt export gzip during format validation.', 'hits' => []];
            }
            if ($chunk !== '') {
                fwrite($out, $chunk);
            }
        }
        gzclose($in);
        fclose($out);

        orange_backup_pdo_validate_export_format($tmpSql, $tableCount);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'hits' => []];
    } finally {
        if (is_file($tmpSql)) {
            @unlink($tmpSql);
        }
    }

    return ['ok' => true, 'error' => null, 'hits' => []];
}

/**
 * @return array{ok:bool,error:?string,hits:list<string>}
 */
function orange_restore_merge_staging_export_scan_cross_schema(
    string $gzipPath,
    string $targetDb,
    string $forbiddenOtherDb
): array {
    if (!is_file($gzipPath) || !function_exists('gzopen')) {
        return ['ok' => false, 'error' => 'Export gzip unavailable for cross-schema scan.', 'hits' => []];
    }

    $handle = @gzopen($gzipPath, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'Cannot open export gzip for cross-schema scan.', 'hits' => []];
    }

    $buffer = '';
    try {
        while (!gzeof($handle)) {
            $chunk = gzread($handle, 65536);
            if ($chunk === false) {
                throw new RuntimeException('Corrupt export gzip during cross-schema scan.');
            }
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;

            while (true) {
                $split = orange_restore_sql_runner_split_next_statement($buffer);
                if ($split === null) {
                    break;
                }
                $buffer = $split['remainder'];
                $statement = trim($split['statement']);
                if ($statement === '' || orange_restore_sql_is_comment_only($statement)) {
                    continue;
                }
                orange_restore_sql_validate_statement_for_target($statement, $targetDb, $forbiddenOtherDb);
            }
        }

        $tail = trim($buffer);
        if ($tail !== '' && !orange_restore_sql_is_comment_only($tail)) {
            throw new RuntimeException('Export gzip ended with incomplete SQL statement.');
        }
    } catch (Throwable $e) {
        gzclose($handle);

        return ['ok' => false, 'error' => $e->getMessage(), 'hits' => ['cross-schema or forbidden statement']];
    }

    gzclose($handle);

    return ['ok' => true, 'error' => null, 'hits' => []];
}
