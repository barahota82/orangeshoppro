<?php

declare(strict_types=1);

/**
 * أرشيف الجرد — مرفقات تقرير الجرد المطبوع الموقّع (قرار المالك 2026-06-16).
 *
 * تُخزَّن الملفات تحت project_root/uploads/stocktake/{reconciliation_id}/ وتُسرَد كقائمة JSON
 * في العمود inventory_reconciliation.attachments_json. التنزيل عبر سكربت يتحقق من الجلسة
 * (المسار غير عام). الأنواع المسموحة: PDF + صور + Excel/Word.
 */

require_once __DIR__ . '/upload_paths.php';

function orange_stocktake_archive_upload_root(): string
{
    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'stocktake';
}

function orange_ensure_stocktake_archive_dir(int $reconciliationId): ?string
{
    if ($reconciliationId <= 0) {
        return null;
    }
    $uploadsRoot = orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads';
    $base = orange_stocktake_archive_upload_root();
    $dir = $base . DIRECTORY_SEPARATOR . $reconciliationId;
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
 * امتدادات Office المسموحة (احتياطي عندما يكتشف finfo نوعاً عاماً مثل application/zip).
 *
 * @return array<string,string> ext => mime
 */
function orange_stocktake_archive_office_ext_map(): array
{
    return [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'  => 'application/msword',
    ];
}

/**
 * هل النوع مسموح؟ يقبل: PDF، صور (عدا SVG)، Excel/Word.
 * عند نوع عام (zip/octet-stream) يُسمح إن كان الامتداد ضمن مستندات Office.
 */
function orange_stocktake_archive_is_allowed(string $mime, string $originalName): bool
{
    $m = strtolower(trim($mime));
    if ($m === 'application/pdf') {
        return true;
    }
    if (str_starts_with($m, 'image/') && $m !== 'image/svg+xml') {
        return true;
    }
    $officeMimes = array_values(orange_stocktake_archive_office_ext_map());
    if (in_array($m, $officeMimes, true)) {
        return true;
    }
    // xlsx/docx حاويات zip — قد يكتشفها finfo كـ zip/octet-stream؛ نتحقق بالامتداد.
    if (in_array($m, ['application/zip', 'application/octet-stream', 'application/CDFV2', ''], true)) {
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        return isset(orange_stocktake_archive_office_ext_map()[$ext]);
    }

    return false;
}

function orange_stocktake_archive_extension_from_mime(string $mime, string $originalName): string
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
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/msword' => 'doc',
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

function orange_stocktake_archive_safe_original_name(string $name): string
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

function orange_stocktake_archive_safe_display_name(string $name, string $fallback = ''): string
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
 * التخزين المؤقت قبل الحفظ (Draft): تُرفع المرفقات لمجلد مؤقت باسم رمز عشوائي،
 * ثم تُنقل إلى مجلد السجل عند الحفظ. هذا يسمح بإضافة المرفقات قبل إنشاء السجل.
 */
function orange_stocktake_archive_is_valid_draft_token(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
}

function orange_stocktake_archive_draft_root(): string
{
    return orange_stocktake_archive_upload_root() . DIRECTORY_SEPARATOR . '_drafts';
}

function orange_ensure_stocktake_archive_draft_dir(string $token): ?string
{
    if (! orange_stocktake_archive_is_valid_draft_token($token)) {
        return null;
    }
    $uploadsRoot = orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads';
    $base = orange_stocktake_archive_upload_root();
    $draftsRoot = orange_stocktake_archive_draft_root();
    $dir = $draftsRoot . DIRECTORY_SEPARATOR . $token;
    foreach ([$uploadsRoot, $base, $draftsRoot, $dir] as $path) {
        if (is_file($path)) {
            return null;
        }
        if (! is_dir($path)) {
            if (! @mkdir($path, 0755, true) && ! is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
        if (! is_dir($path) || ! is_writable($path)) {
            return null;
        }
    }

    return $dir;
}

/**
 * ينقل المرفقات المؤقتة إلى مجلد السجل بعد إنشائه ويعيد القائمة النهائية بالمسارات الجديدة.
 *
 * @param list<array<string,mixed>> $stagedList
 * @return list<array<string,mixed>>
 */
function orange_stocktake_archive_finalize_draft(int $reconciliationId, string $token, array $stagedList): array
{
    $out = [];
    if ($reconciliationId <= 0 || ! orange_stocktake_archive_is_valid_draft_token($token)) {
        return $out;
    }
    $destDir = orange_ensure_stocktake_archive_dir($reconciliationId);
    if ($destDir === null) {
        return $out;
    }
    $draftDir = orange_stocktake_archive_draft_root() . DIRECTORY_SEPARATOR . $token;
    $expectedPrefix = 'stocktake/_drafts/' . $token . '/';
    foreach ($stagedList as $item) {
        if (! is_array($item)) {
            continue;
        }
        $path = ltrim(str_replace('\\', '/', trim((string) ($item['path'] ?? ''))), '/');
        if (! str_starts_with($path, $expectedPrefix) || str_contains($path, '..')) {
            continue;
        }
        $base = basename($path);
        if ($base === '') {
            continue;
        }
        $srcAbs = $draftDir . DIRECTORY_SEPARATOR . $base;
        if (! is_file($srcAbs)) {
            continue;
        }
        $mime = trim((string) ($item['mime'] ?? ''));
        $originalName = orange_stocktake_archive_safe_original_name((string) ($item['original_name'] ?? $base));
        if (! orange_stocktake_archive_is_allowed($mime, $originalName)) {
            @unlink($srcAbs);
            continue;
        }
        $ext = orange_stocktake_archive_extension_from_mime($mime, $originalName);
        $safe = 'st_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destAbs = $destDir . DIRECTORY_SEPARATOR . $safe;
        if (! @rename($srcAbs, $destAbs)) {
            if (! @copy($srcAbs, $destAbs)) {
                continue;
            }
            @unlink($srcAbs);
        }
        $out[] = [
            'id' => bin2hex(random_bytes(8)),
            'name' => orange_stocktake_archive_safe_display_name((string) ($item['name'] ?? ''), pathinfo($originalName, PATHINFO_FILENAME)),
            'path' => 'stocktake/' . $reconciliationId . '/' . $safe,
            'mime' => $mime !== '' ? $mime : 'application/octet-stream',
            'size' => is_file($destAbs) ? (int) filesize($destAbs) : 0,
            'uploaded_at' => date('Y-m-d H:i:s'),
            'original_name' => $originalName,
        ];
    }
    orange_stocktake_archive_cleanup_draft($token);

    return $out;
}

function orange_stocktake_archive_cleanup_draft(string $token): void
{
    if (! orange_stocktake_archive_is_valid_draft_token($token)) {
        return;
    }
    $dir = orange_stocktake_archive_draft_root() . DIRECTORY_SEPARATOR . $token;
    if (! is_dir($dir)) {
        return;
    }
    foreach ((array) @scandir($dir) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        @unlink($dir . DIRECTORY_SEPARATOR . $f);
    }
    @rmdir($dir);
}

/**
 * تنظيف المجلدات المؤقتة المهجورة (افتراضياً أقدم من يوم).
 */
function orange_stocktake_archive_cleanup_old_drafts(int $maxAgeSeconds = 86400): void
{
    $root = orange_stocktake_archive_draft_root();
    if (! is_dir($root)) {
        return;
    }
    $now = time();
    foreach ((array) @scandir($root) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $dir = $root . DIRECTORY_SEPARATOR . $f;
        if (! is_dir($dir)) {
            continue;
        }
        $mt = @filemtime($dir);
        if ($mt !== false && ($now - $mt) > $maxAgeSeconds) {
            foreach ((array) @scandir($dir) as $g) {
                if ($g === '.' || $g === '..') {
                    continue;
                }
                @unlink($dir . DIRECTORY_SEPARATOR . $g);
            }
            @rmdir($dir);
        }
    }
}

/**
 * @return list<array{id:string,name:string,path:string,mime:string,size:int,uploaded_at:string,original_name:string}>
 */
function orange_stocktake_archive_decode_list(?string $json): array
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
        if (!str_starts_with($path, 'stocktake/')) {
            continue;
        }
        $out[] = [
            'id' => $id,
            'name' => orange_stocktake_archive_safe_display_name($name, pathinfo($originalName, PATHINFO_FILENAME)),
            'path' => $path,
            'mime' => $mime,
            'size' => max(0, $size),
            'uploaded_at' => $uploadedAt,
            'original_name' => orange_stocktake_archive_safe_original_name($originalName),
        ];
    }

    return $out;
}

function orange_stocktake_archive_encode_list(array $list): ?string
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

function orange_stocktake_archive_abs_path(string $relativePath): string
{
    $rel = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($relativePath)), DIRECTORY_SEPARATOR);

    return orange_project_root_path() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $rel;
}

function orange_stocktake_archive_is_within_upload_root(string $absPath): bool
{
    $rootReal = @realpath(orange_stocktake_archive_upload_root());
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
 * يقرأ قائمة مرفقات سجل أرشيف من العمود attachments_json.
 *
 * @return list<array<string,mixed>>
 */
function orange_stocktake_archive_attachments_for(PDO $pdo, int $reconciliationId): array
{
    if ($reconciliationId <= 0 || !orange_table_has_column($pdo, 'inventory_reconciliation', 'attachments_json')) {
        return [];
    }
    $st = $pdo->prepare('SELECT attachments_json FROM inventory_reconciliation WHERE id = ? LIMIT 1');
    $st->execute([$reconciliationId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [];
    }

    return orange_stocktake_archive_decode_list((string) ($row['attachments_json'] ?? ''));
}

/**
 * يحفظ قائمة المرفقات في العمود attachments_json لسجل أرشيف.
 */
function orange_stocktake_archive_store_attachments(PDO $pdo, int $reconciliationId, array $list): void
{
    $json = orange_stocktake_archive_encode_list($list);
    $up = $pdo->prepare('UPDATE inventory_reconciliation SET attachments_json = ? WHERE id = ?');
    $up->execute([$json, $reconciliationId]);
}
