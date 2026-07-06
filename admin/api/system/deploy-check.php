<?php

declare(strict_types=1);

/**
 * للمشرفين: التحقق السريع بعد النشر (تلقائي أو يدوي) أن ملفات/مخطط المحاسبة الحديثة موجودة على هذا السيرفر.
 * افتح الرابط في المتصفح — يجب أن يظهر JSON فقط.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/schema_migrations.php';
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

    $pathEditLock = $root . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'edit_lock.php';
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
            'file_edit_lock_page' => is_file($pathEditLock),
            'file_includes_gl_pending_movements' => is_file($pathGlPendingInc),
            'table_orange_gl_pending_movements' => orange_table_exists($pdo, 'orange_gl_pending_movements'),
        ],
        'file_mtimes_unix' => [
            'admin_pages_edit_lock' => $mt($pathEditLock),
            'includes_gl_pending_movements' => $mt($pathGlPendingInc),
            'admin_assets_admin_js' => $mt($pathAdminJs),
        ],
        'migration_operational' => orange_schema_migration_operational_status($pdo),
        'hint' => 'بعد النشر: file_edit_lock_page و includes_gl_pending_movements يجب أن تكون true. جدول orange_gl_pending_movements legacy اختياري. Optional: ORANGE_BUILD_REF في .env.php.',
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر فحص النشر');
}
