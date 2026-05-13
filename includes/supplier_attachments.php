<?php

declare(strict_types=1);

require_once __DIR__ . '/upload_paths.php';

function orange_supplier_attachment_upload_root(): string
{
    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'suppliers';
}

function orange_ensure_supplier_attachment_dir(int $supplierId): ?string
{
    if ($supplierId <= 0) {
        return null;
    }
    $uploadsRoot = orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads';
    $base = orange_supplier_attachment_upload_root();
    $dir = $base . DIRECTORY_SEPARATOR . $supplierId;
    foreach ([$uploadsRoot, $base, $dir] as $path) {
        if (is_file($path)) {
            return null;
        }
        if (!is_dir($path)) {
            if (!@mkdir($path, 0755, true) && !is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
        if (!is_dir($path) || !is_writable($path)) {
            return null;
        }
    }

    return $dir;
}

function orange_supplier_attachment_is_allowed_mime(string $mime): bool
{
    $m = strtolower(trim($mime));
    if ($m === 'application/pdf') {
        return true;
    }
    if (!str_starts_with($m, 'image/')) {
        return false;
    }
    if ($m === 'image/svg+xml') {
        return false;
    }

    return true;
}

function orange_supplier_attachment_extension_from_mime(string $mime, string $originalName): string
{
    $m = strtolower(trim($mime));
    $map = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/tiff' => 'tif',
        'image/avif' => 'avif',
    ];
    if (isset($map[$m])) {
        return $map[$m];
    }
    $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== '' && preg_match('/^[a-z0-9]{2,8}$/', $ext)) {
        return $ext;
    }

    return 'bin';
}

function orange_supplier_attachment_safe_original_name(string $name): string
{
    $base = basename(trim($name));
    $base = preg_replace('/[^a-zA-Z0-9._\x{0600}-\x{06FF}\- ]+/u', '_', $base) ?? '';
    $base = trim($base, " \t\n\r\0\x0B._");
    if ($base === '') {
        $base = 'file';
    }
    if (function_exists('mb_substr')) {
        $base = mb_substr($base, 0, 191, 'UTF-8');
    } else {
        $base = substr($base, 0, 191);
    }

    return $base;
}

function orange_supplier_attachment_safe_display_name(string $name, string $fallback = ''): string
{
    $raw = trim($name);
    if ($raw === '') {
        $raw = trim($fallback);
    }
    $raw = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw) ?? '';
    $raw = trim($raw);
    if ($raw === '') {
        $raw = 'مرفق';
    }
    if (function_exists('mb_substr')) {
        $raw = mb_substr($raw, 0, 191, 'UTF-8');
    } else {
        $raw = substr($raw, 0, 191);
    }

    return $raw;
}

/**
 * @return list<array{
 *   id:string,
 *   name:string,
 *   path:string,
 *   mime:string,
 *   size:int,
 *   uploaded_at:string,
 *   original_name:string
 * }>
 */
function orange_supplier_attachment_decode_list(?string $json): array
{
    $raw = trim((string) $json);
    if ($raw === '') {
        return [];
    }
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        return [];
    }
    $out = [];
    foreach ($arr as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = trim((string) ($item['id'] ?? ''));
        $name = trim((string) ($item['name'] ?? ''));
        $path = str_replace('\\', '/', trim((string) ($item['path'] ?? '')));
        $mime = trim((string) ($item['mime'] ?? ''));
        $size = (int) ($item['size'] ?? 0);
        $uploadedAt = trim((string) ($item['uploaded_at'] ?? ''));
        $originalName = trim((string) ($item['original_name'] ?? ''));
        if ($id === '' || $path === '' || str_contains($path, '..')) {
            continue;
        }
        $path = ltrim($path, '/');
        if (!str_starts_with($path, 'suppliers/')) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'name' => orange_supplier_attachment_safe_display_name($name, pathinfo($originalName, PATHINFO_FILENAME)),
            'path' => $path,
            'mime' => $mime,
            'size' => max(0, $size),
            'uploaded_at' => $uploadedAt,
            'original_name' => orange_supplier_attachment_safe_original_name($originalName),
        ];
    }

    return $out;
}

function orange_supplier_attachment_encode_list(array $list): ?string
{
    if ($list === []) {
        return null;
    }
    $json = json_encode(array_values($list), JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
        return null;
    }

    return $json;
}

function orange_supplier_attachment_abs_path(string $relativePath): string
{
    $rel = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($relativePath)), DIRECTORY_SEPARATOR);

    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $rel;
}

function orange_supplier_attachment_is_within_upload_root(string $absPath): bool
{
    $rootReal = @realpath(orange_supplier_attachment_upload_root());
    $fileReal = @realpath($absPath);
    if ($rootReal === false || $fileReal === false) {
        return false;
    }
    $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/');
    $fileNorm = str_replace('\\', '/', $fileReal);
    if ($fileNorm === $rootNorm) {
        return true;
    }

    return str_starts_with($fileNorm, $rootNorm . '/');
}

/**
 * @return array{path:string,mime:string,size:int}
 */
function orange_supplier_attachment_optimize_image(string $srcAbs, string $srcMime): array
{
    $result = [
        'path' => $srcAbs,
        'mime' => $srcMime,
        'size' => is_file($srcAbs) ? (int) filesize($srcAbs) : 0,
    ];
    $safeConvertibleMimes = ['image/jpeg', 'image/png', 'image/bmp', 'image/x-ms-bmp'];
    if (!in_array(strtolower(trim($srcMime)), $safeConvertibleMimes, true)) {
        return $result;
    }
    if (!is_file($srcAbs) || !is_readable($srcAbs)) {
        return $result;
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
        return $result;
    }
    $content = @file_get_contents($srcAbs);
    if ($content === false) {
        return $result;
    }
    $im = @imagecreatefromstring($content);
    if ($im === false) {
        return $result;
    }
    if (function_exists('imagepalettetotruecolor')) {
        @imagepalettetotruecolor($im);
    }
    if (function_exists('imagealphablending')) {
        @imagealphablending($im, true);
    }
    if (function_exists('imagesavealpha')) {
        @imagesavealpha($im, true);
    }
    $stem = (string) pathinfo($srcAbs, PATHINFO_FILENAME);
    $webpAbs = dirname($srcAbs) . DIRECTORY_SEPARATOR . $stem . '.webp';
    @imagewebp($im, $webpAbs, 82);
    imagedestroy($im);
    if (!is_file($webpAbs)) {
        return $result;
    }
    $webpSize = (int) filesize($webpAbs);
    if ($webpSize > 0 && $webpSize < $result['size']) {
        @unlink($srcAbs);

        return [
            'path' => $webpAbs,
            'mime' => 'image/webp',
            'size' => $webpSize,
        ];
    }
    @unlink($webpAbs);

    return $result;
}

function orange_supplier_attachment_find_ghostscript_binary(): ?string
{
    if (!function_exists('exec')) {
        return null;
    }
    $candidates = [];
    $env = trim((string) getenv('GS_EXECUTABLE'));
    if ($env !== '') {
        $candidates[] = $env;
    }
    array_push($candidates, 'gswin64c', 'gswin32c', 'gs');
    foreach ($candidates as $bin) {
        $bin = trim((string) $bin);
        if ($bin === '') {
            continue;
        }
        $out = [];
        $code = 1;
        @exec(escapeshellcmd($bin) . ' -version', $out, $code);
        if ($code === 0) {
            return $bin;
        }
    }

    return null;
}

/**
 * @return array{path:string,mime:string,size:int}
 */
function orange_supplier_attachment_optimize_pdf(string $srcAbs): array
{
    $result = [
        'path' => $srcAbs,
        'mime' => 'application/pdf',
        'size' => is_file($srcAbs) ? (int) filesize($srcAbs) : 0,
    ];
    if (!is_file($srcAbs) || !is_readable($srcAbs)) {
        return $result;
    }
    $bin = orange_supplier_attachment_find_ghostscript_binary();
    if ($bin === null || !function_exists('exec')) {
        return $result;
    }
    $compressed = dirname($srcAbs) . DIRECTORY_SEPARATOR . pathinfo($srcAbs, PATHINFO_FILENAME) . '.compressed.pdf';
    $cmd = escapeshellcmd($bin)
        . ' -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dQUIET -dBATCH'
        . ' -sOutputFile=' . escapeshellarg($compressed)
        . ' ' . escapeshellarg($srcAbs);
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($compressed)) {
        @unlink($compressed);

        return $result;
    }
    $newSize = (int) filesize($compressed);
    if ($newSize > 0 && $newSize < $result['size']) {
        @unlink($srcAbs);

        return [
            'path' => $compressed,
            'mime' => 'application/pdf',
            'size' => $newSize,
        ];
    }
    @unlink($compressed);

    return $result;
}
