<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/backup/backup_admin.php';

backup_admin_api_begin_json_only();
require_admin_api();

function backup_admin_api_begin_json_only(): void
{
    static $started = false;
    if ($started) {
        return;
    }
    $started = true;

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    if (ob_get_level() === 0) {
        ob_start();
    }

    register_shutdown_function('backup_admin_api_json_shutdown_guard');
}

function backup_admin_api_json_shutdown_guard(): void
{
    if (defined('ORANGE_JSON_RESPONSE_EMITTED')) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        return;
    }

    $buffer = '';
    while (ob_get_level() > 0) {
        $buffer .= (string) ob_get_clean();
    }
    if ($buffer === '') {
        return;
    }

    $excerpt = substr(preg_replace('/\s+/u', ' ', $buffer) ?? $buffer, 0, 500);
    error_log('[orange backup admin api] non-json response suppressed (' . strlen($buffer) . ' bytes): ' . $excerpt);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ غير متوقع',
    ], JSON_UNESCAPED_UNICODE);
}

function backup_admin_api_project_root(): string
{
    return dirname(__DIR__, 3);
}

/**
 * @return array<string, mixed>
 */
function backup_admin_api_admin(): array
{
    $admin = $GLOBALS['orange_admin_active_record'] ?? null;
    if (!is_array($admin)) {
        throw new RuntimeException('Admin session missing.');
    }

    return $admin;
}

function backup_admin_api_pdo(): PDO
{
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    return $pdo;
}

function backup_admin_api_require_get(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        json_response(['success' => false, 'message' => 'Method not allowed'], 405);
    }
}

function backup_admin_api_require_post(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        json_response(['success' => false, 'message' => 'Method not allowed'], 405);
    }
}

/**
 * @return array<string, mixed>
 */
function backup_admin_api_json_body(): array
{
    $data = get_json_input();
    if (is_array($data) && $data !== []) {
        return $data;
    }
    if ($_POST !== []) {
        return $_POST;
    }

    return [];
}

function backup_admin_api_require_csrf(array $data): void
{
    $token = (string) ($data['csrf_token'] ?? $data['_csrf'] ?? '');
    orange_backup_admin_verify_csrf($token);
}

function backup_admin_api_safe_message(Throwable $e): string
{
    $msg = trim($e->getMessage());
    if ($msg === '') {
        return 'تعذر تنفيذ العملية.';
    }
    if (str_contains($msg, 'permission') || str_contains($msg, 'CSRF') || str_contains($msg, 'Invalid')
        || str_contains($msg, 'not found') || str_contains($msg, 'traversal') || str_contains($msg, 'allowlisted')
        || str_contains($msg, 'already running') || str_contains($msg, 'lock')) {
        return $msg;
    }

    return 'تعذر تنفيذ العملية.';
}

function backup_admin_api_body_looks_like_html(string $body): bool
{
    $trim = ltrim($body);
    if ($trim === '') {
        return false;
    }

    return str_starts_with($trim, '<') || stripos($trim, '<!DOCTYPE') === 0 || stripos($trim, '<html') === 0;
}
