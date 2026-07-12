<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_paths.php';

/** @var list<string> */
const ORANGE_COUNTRY_UPLOADS_ALLOWLIST_PREFIXES = [
    'uploads/products/',
    'uploads/payment_proofs/',
    'uploads/company_docs/',
    'uploads/customers/',
    'uploads/suppliers/',
    'uploads/stocktake/',
    'uploads/company/',
];

/**
 * @param array<string, list<array<string,mixed>>> $exportedRows keyed by table name
 * @return array{
 *   files:list<array{relative_path:string,sha256:string,size_bytes:int,source_table:string,source_id:string,severity:string}>,
 *   issues:list<string>,
 *   collected:int,
 *   missing:int
 * }
 */
function orange_country_uploads_collect(string $projectRoot, int $countryId, array $exportedRows): array
{
    $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    if ($uploadsRoot === false || !is_dir($uploadsRoot)) {
        return [
            'files' => [],
            'issues' => ['critical:uploads root missing or unreadable'],
            'collected' => 0,
            'missing' => 0,
        ];
    }

    $candidates = orange_country_uploads_discover_candidates($exportedRows, $countryId);
    $files = [];
    $issues = [];
    $missing = 0;
    $seenFiles = [];

    foreach ($candidates as $candidate) {
        $relative = orange_country_uploads_normalize_relative((string) $candidate['relative_path']);
        if ($relative === '' || !orange_country_uploads_is_allowlisted($relative)) {
            $issues[] = 'critical:upload path not allowlisted: ' . $relative;
            $missing++;
            continue;
        }
        $resolvedFiles = orange_country_uploads_resolve_candidate_files($projectRoot, $relative);
        if ($resolvedFiles === []) {
            $severity = (string) ($candidate['severity'] ?? 'warning');
            if ($severity !== 'informational') {
                $issues[] = $severity . ':missing upload file: ' . $relative;
                $missing++;
            }
            continue;
        }
        foreach ($resolvedFiles as $resolved) {
            $fileRelative = $resolved['relative_path'];
            if (isset($seenFiles[$fileRelative])) {
                continue;
            }
            $seenFiles[$fileRelative] = true;
            $files[] = [
                'relative_path' => $fileRelative,
                'sha256' => hash_file('sha256', $resolved['absolute_path']) ?: '',
                'size_bytes' => (int) filesize($resolved['absolute_path']),
                'source_table' => (string) ($candidate['source_table'] ?? ''),
                'source_id' => (string) ($candidate['source_id'] ?? ''),
                'severity' => (string) ($candidate['severity'] ?? 'warning'),
            ];
        }
    }

    return [
        'files' => $files,
        'issues' => $issues,
        'collected' => count($files),
        'missing' => $missing,
    ];
}

/**
 * @param array<string, list<array<string,mixed>>> $exportedRows
 * @return list<array{relative_path:string,source_table:string,source_id:string,severity:string}>
 */
function orange_country_uploads_discover_candidates(array $exportedRows, int $countryId): array
{
    $out = [];
    $seen = [];

    $add = static function (string $path, string $table, string $id, string $severity) use (&$out, &$seen): void {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return;
        }
        if (!str_starts_with($path, 'uploads/')) {
            if (str_starts_with($path, '/uploads/')) {
                $path = ltrim($path, '/');
            } else {
                $path = 'uploads/products/' . ltrim($path, '/');
            }
        }
        if (isset($seen[$path])) {
            return;
        }
        $seen[$path] = true;
        $out[] = [
            'relative_path' => $path,
            'source_table' => $table,
            'source_id' => $id,
            'severity' => $severity,
        ];
    };

    foreach ($exportedRows['products'] ?? [] as $row) {
        $id = (string) ($row['id'] ?? '');
        $mainImage = trim((string) ($row['main_image'] ?? ''));
        if ($mainImage !== '') {
            $add($mainImage, 'products', $id, 'critical');
        }
    }
    foreach ($exportedRows['product_images'] ?? [] as $row) {
        $add((string) ($row['image_path'] ?? ''), 'product_images', (string) ($row['id'] ?? ''), 'critical');
    }
    foreach ($exportedRows['product_colorway_images'] ?? [] as $row) {
        $add((string) ($row['image_path'] ?? ''), 'product_colorway_images', (string) ($row['id'] ?? ''), 'warning');
    }
    foreach ($exportedRows['payment_transactions'] ?? [] as $row) {
        $proof = trim((string) ($row['proof_file'] ?? ''));
        if ($proof !== '') {
            $add('uploads/payment_proofs/' . ltrim($proof, '/'), 'payment_transactions', (string) ($row['id'] ?? ''), 'critical');
        }
    }
    foreach ($exportedRows['orange_company_documents'] ?? [] as $row) {
        $add((string) ($row['storage_path'] ?? ''), 'orange_company_documents', (string) ($row['id'] ?? ''), 'critical');
    }

    foreach ($exportedRows['customers'] ?? [] as $row) {
        $customerId = (int) ($row['id'] ?? 0);
        if ($customerId > 0) {
            $add('uploads/customers/' . $customerId . '/', 'customers', (string) $customerId, 'informational');
        }
    }
    foreach ($exportedRows['suppliers'] ?? [] as $row) {
        $supplierId = (int) ($row['id'] ?? 0);
        if ($supplierId > 0) {
            $add('uploads/suppliers/' . $supplierId . '/', 'suppliers', (string) $supplierId, 'informational');
        }
    }
    foreach ($exportedRows['inventory_reconciliation'] ?? [] as $row) {
        $recId = (int) ($row['id'] ?? 0);
        if ($recId > 0) {
            $add('uploads/stocktake/' . $recId . '/', 'inventory_reconciliation', (string) $recId, 'warning');
        }
    }

    foreach ($exportedRows['company_settings'] ?? [] as $row) {
        $logo = trim((string) ($row['company_logo'] ?? ''));
        if ($logo !== '') {
            $logoPath = str_starts_with($logo, 'uploads/') || str_starts_with($logo, '/uploads/')
                ? $logo
                : 'uploads/company/' . ltrim($logo, '/');
            $add($logoPath, 'company_settings', (string) ($row['id'] ?? ''), 'warning');
        }
    }

    return $out;
}

function orange_country_uploads_normalize_relative(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return '';
    }
    if (str_contains($path, '..')) {
        return '';
    }
    if (str_starts_with($path, '/uploads/')) {
        return ltrim($path, '/');
    }
    if (str_starts_with($path, 'uploads/')) {
        return $path;
    }

    return 'uploads/products/' . ltrim($path, '/');
}

function orange_country_uploads_is_allowlisted(string $relativePath): bool
{
    foreach (ORANGE_COUNTRY_UPLOADS_ALLOWLIST_PREFIXES as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            return true;
        }
    }

    return false;
}

function orange_country_uploads_resolve_absolute(string $projectRoot, string $relativePath): ?string
{
    if (str_contains($relativePath, '..')) {
        return null;
    }
    if (str_ends_with($relativePath, '/')) {
        return null;
    }
    $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $real = realpath($abs);
    if ($real === false || !is_file($real)) {
        return null;
    }
    $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    if ($uploadsRoot === false) {
        return null;
    }
    $normFile = str_replace('\\', '/', strtolower($real));
    $normUploads = str_replace('\\', '/', strtolower($uploadsRoot));
    if (!str_starts_with($normFile, $normUploads . '/')) {
        return null;
    }

    return $real;
}

/**
 * @return list<array{relative_path:string,absolute_path:string}>
 */
function orange_country_uploads_resolve_candidate_files(string $projectRoot, string $relativePath): array
{
    if (str_contains($relativePath, '..')) {
        return [];
    }
    $uploadsRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads');
    if ($uploadsRoot === false) {
        return [];
    }

    $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $real = realpath($abs);
    if ($real === false) {
        return [];
    }

    if (is_file($real)) {
        $relative = orange_country_uploads_relative_from_absolute($projectRoot, $real);
        if ($relative === null || !orange_country_uploads_is_allowlisted($relative)) {
            return [];
        }

        return [['relative_path' => $relative, 'absolute_path' => $real]];
    }

    if (!is_dir($real)) {
        return [];
    }

    $normDir = str_replace('\\', '/', strtolower(rtrim($real, '\\/')));
    $normUploads = str_replace('\\', '/', strtolower(rtrim($uploadsRoot, '\\/')));
    if ($normDir !== $normUploads && !str_starts_with($normDir, $normUploads . '/')) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        $fileReal = $fileInfo->getRealPath();
        if (!is_string($fileReal) || $fileReal === '') {
            continue;
        }
        $relative = orange_country_uploads_relative_from_absolute($projectRoot, $fileReal);
        if ($relative === null || !orange_country_uploads_is_allowlisted($relative)) {
            continue;
        }
        $files[] = ['relative_path' => $relative, 'absolute_path' => $fileReal];
    }
    usort($files, static fn (array $a, array $b): int => strcmp($a['relative_path'], $b['relative_path']));

    return $files;
}

function orange_country_uploads_relative_from_absolute(string $projectRoot, string $absolutePath): ?string
{
    $projectReal = realpath($projectRoot);
    if ($projectReal === false) {
        return null;
    }
    $normProject = str_replace('\\', '/', rtrim($projectReal, '\\/'));
    $normFile = str_replace('\\', '/', $absolutePath);
    if (!str_starts_with($normFile, $normProject . '/')) {
        return null;
    }
    $relative = substr($normFile, strlen($normProject) + 1);
    $relative = orange_country_uploads_normalize_relative($relative);

    return $relative !== '' ? $relative : null;
}

/**
 * @param list<array{relative_path:string,sha256:string,size_bytes:int,source_table:string,source_id:string,severity:string}> $files
 */
function orange_country_uploads_write_zip(string $zipPath, string $projectRoot, array $files): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension is required for country uploads export.');
    }
    $dir = dirname($zipPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create uploads zip directory.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create uploads_country.zip');
    }
    foreach ($files as $file) {
        $relative = (string) ($file['relative_path'] ?? '');
        $abs = orange_country_uploads_resolve_absolute($projectRoot, $relative);
        if ($abs === null) {
            continue;
        }
        $zip->addFile($abs, str_replace('\\', '/', $relative));
    }
    $zip->close();
}

/**
 * @param list<array{relative_path:string,sha256:string,size_bytes:int,source_table:string,source_id:string,severity:string}> $files
 */
function orange_country_uploads_write_empty_zip(string $zipPath): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension is required for country uploads export.');
    }
    $dir = dirname($zipPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create uploads zip directory.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create uploads_country.zip');
    }
    $zip->addFromString('README.txt', "Orange CRP uploads archive — no files referenced for this export.\n");
    $zip->close();
}
