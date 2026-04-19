<?php

declare(strict_types=1);

/**
 * للمشرفين: التحقق السريع بعد النشر (تلقائي أو يدوي) أن ملفات/مخطط المحاسبة الحديثة موجودة على هذا السيرفر.
 * افتح الرابط في المتصفح — يجب أن يظهر JSON فقط.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    global $env;
    $envArr = is_array($env) ? $env : [];
    $buildRef = trim((string) ($envArr['ORANGE_BUILD_REF'] ?? ''));
    $root = realpath(__DIR__ . '/../../..');
    if ($root === false) {
        $root = dirname(__DIR__, 3);
    }

    $pathPendingList = $root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'gl' . DIRECTORY_SEPARATOR . 'pending-list.php';
    $pathGlPendingInc = $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'gl_pending_movements.php';
    $pathAdminJs = $root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'admin.js';

    $mt = static function (string $p): ?int {
        if (!is_file($p)) {
            return null;
        }
        $t = @filemtime($p);

        return $t !== false ? $t : null;
    };

    json_response([
        'success' => true,
        'server_time' => date('c'),
        'orange_build_ref' => $buildRef !== '' ? $buildRef : null,
        'markers' => [
            'file_gl_pending_list' => is_file($pathPendingList),
            'file_includes_gl_pending_movements' => is_file($pathGlPendingInc),
            'table_orange_gl_pending_movements' => orange_table_exists($pdo, 'orange_gl_pending_movements'),
        ],
        'file_mtimes_unix' => [
            'admin_api_gl_pending_list' => $mt($pathPendingList),
            'includes_gl_pending_movements' => $mt($pathGlPendingInc),
            'admin_assets_admin_js' => $mt($pathAdminJs),
        ],
        'hint' => 'بعد النشر: يجب أن تكون markers الثلاثة true. Optional: ضع ORANGE_BUILD_REF في .env.php (نص مرجعي من الـ CI) ليظهر هنا.',
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => 'تعذر فحص النشر',
        'error' => $e->getMessage(),
    ], 500);
}
