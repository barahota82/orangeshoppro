<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/company_documents.php';

$admin = require_admin_page();
$pdo = db();
orange_catalog_ensure_schema($pdo);
orange_admin_require_page($admin, $pdo, 'company_documents');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$st = $pdo->prepare('SELECT * FROM orange_company_documents WHERE id = ? LIMIT 1');
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$abs = orange_company_document_absolute_path($row);
if (!is_file($abs) || !is_readable($abs)) {
    http_response_code(410);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'الملف غير موجود على الخادم';
    exit;
}

$mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
if ($mime === '') {
    $mime = 'application/octet-stream';
}

$dlName = basename((string) ($row['original_filename'] ?? 'document'));
if ($dlName === '' || $dlName === '.' || $dlName === '..') {
    $dlName = 'document';
}
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $dlName) ?: 'download';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($dlName));

readfile($abs);
exit;
