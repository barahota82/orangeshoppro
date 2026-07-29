<?php

declare(strict_types=1);

/**
 * Test-only PHP built-in server router for D4 HTTP runtime.
 * Document root = temporary worktree. Does not alter Production routes.
 */

$docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? getcwd());
$sessionHint = $docRoot . DIRECTORY_SEPARATOR . '.d4_http_session_path';
$sessionPath = getenv('ORANGE_D4_HTTP_SESSION_PATH') ?: '';
if (($sessionPath === '' || !is_dir($sessionPath)) && is_file($sessionHint)) {
    $sessionPath = trim((string) file_get_contents($sessionHint));
}
if (is_string($sessionPath) && $sessionPath !== '' && is_dir($sessionPath)) {
    session_save_path($sessionPath);
}

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = $docRoot . str_replace('/', DIRECTORY_SEPARATOR, $uri);
if ($uri !== '/' && is_file($path)) {
    return false; // let built-in server serve the exact Production file
}

// Directory index / missing → 404 JSON for APIs, else false
if (str_starts_with($uri, '/api/') || str_starts_with($uri, '/admin/api/')) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'code' => 'not_found', 'message' => 'Not found'], JSON_UNESCAPED_UNICODE);

    return true;
}

return false;
