<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_sql_safety.php';

const ORANGE_RESTORE_STAGING_SUPPORTED_EXPORT_BACKEND = 'php_pdo';

const ORANGE_RESTORE_STAGING_UNSUPPORTED_BACKENDS = [
    'php_mysqldump',
    'mysqldump',
    'powershell',
    'powershell_mysqldump',
];

/**
 * Phase 2B.1 supports php_pdo full-disaster packages only (no DELIMITER/routines dumps).
 *
 * @param array<string, mixed> $manifest
 * @return array{ok:bool,error:?string,export_backend:string}
 */
function orange_restore_package_staging_import_compat(
    string $packagePath,
    array $manifest,
    string $stagingDb,
    string $productionDb
): array {
    $backend = strtolower(trim((string) ($manifest['export_backend'] ?? '')));
    if ($backend === '') {
        return [
            'ok' => false,
            'error' => 'manifest.export_backend is missing; cannot verify Phase 2B.1 package compatibility.',
            'export_backend' => '',
        ];
    }

    if ($backend !== ORANGE_RESTORE_STAGING_SUPPORTED_EXPORT_BACKEND) {
        $hint = in_array($backend, ORANGE_RESTORE_STAGING_UNSUPPORTED_BACKENDS, true)
            ? ' mysqldump/PowerShell packages with routines, triggers, events, or DELIMITER blocks are unsupported in Phase 2B.1.'
            : '';

        return [
            'ok' => false,
            'error' => 'Phase 2B.1 staging restore supports export_backend='
                . ORANGE_RESTORE_STAGING_SUPPORTED_EXPORT_BACKEND
                . ' only (package has '
                . $backend
                . ').'
                . $hint,
            'export_backend' => $backend,
        ];
    }

    $dumpFile = trim((string) ($manifest['dump_file'] ?? ''));
    if ($dumpFile === '') {
        return [
            'ok' => false,
            'error' => 'manifest.dump_file missing; cannot scan SQL dump for staging safety.',
            'export_backend' => $backend,
        ];
    }

    $dumpPath = $packagePath . DIRECTORY_SEPARATOR . $dumpFile;
    $scan = orange_restore_sql_scan_gzip_forbidden_patterns($dumpPath, $stagingDb, $productionDb);
    if (!$scan['ok']) {
        return [
            'ok' => false,
            'error' => (string) ($scan['error'] ?? 'SQL dump failed staging safety scan.'),
            'export_backend' => $backend,
        ];
    }

    return ['ok' => true, 'error' => null, 'export_backend' => $backend];
}
