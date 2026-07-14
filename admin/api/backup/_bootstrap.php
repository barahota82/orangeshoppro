<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/backup/backup_admin.php';

require_admin_api();

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
