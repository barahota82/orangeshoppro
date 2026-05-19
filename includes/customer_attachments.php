<?php

declare(strict_types=1);

require_once __DIR__ . '/upload_paths.php';
require_once __DIR__ . '/supplier_attachments.php';

/**
 * س15: مرفقات العميل — نسخة موازية لمرفقات المورد، مع تخصيص مسار التخزين `customers/{id}/`.
 * نُعيد استخدام دوال الموردين العامة (allowed mime, safe names, optimize image/pdf) قدر الإمكان
 * ونوفّر دوال خاصة لمسار وقراءة قائمة المرفقات.
 */

function orange_customer_attachment_upload_root(): string
{
    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'customers';
}

function orange_ensure_customer_attachment_dir(int $customerId): ?string
{
    if ($customerId <= 0) {
        return null;
    }
    $uploadsRoot = orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads';
    $base = orange_customer_attachment_upload_root();
    $dir = $base . DIRECTORY_SEPARATOR . $customerId;
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
function orange_customer_attachment_decode_list(?string $json): array
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
        if (!str_starts_with($path, 'customers/')) {
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

function orange_customer_attachment_encode_list(array $list): ?string
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

function orange_customer_attachment_abs_path(string $relativePath): string
{
    $rel = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($relativePath)), DIRECTORY_SEPARATOR);

    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $rel;
}

function orange_customer_attachment_is_within_upload_root(string $absPath): bool
{
    $rootReal = @realpath(orange_customer_attachment_upload_root());
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
