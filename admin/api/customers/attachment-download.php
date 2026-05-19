<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/customer_attachments.php';

require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    if (!orange_table_exists($pdo, 'customers')) {
        http_response_code(500);
        exit('Service unavailable');
    }
    $customerId = (int) ($_GET['customer_id'] ?? 0);
    $attId = trim((string) ($_GET['id'] ?? ''));
    if ($customerId <= 0 || $attId === '') {
        http_response_code(400);
        exit('Bad request');
    }
    $st = $pdo->prepare('SELECT attachments_json FROM customers WHERE id = ? LIMIT 1');
    $st->execute([$customerId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit('Not found');
    }
    $list = orange_customer_attachment_decode_list((string) ($row['attachments_json'] ?? ''));
    $found = null;
    foreach ($list as $att) {
        if ($att['id'] === $attId) {
            $found = $att;
            break;
        }
    }
    if ($found === null) {
        http_response_code(404);
        exit('Attachment not found');
    }
    $abs = orange_customer_attachment_abs_path($found['path']);
    if (!orange_customer_attachment_is_within_upload_root($abs) || !is_file($abs)) {
        http_response_code(404);
        exit('File missing');
    }
    $mime = (string) ($found['mime'] ?? 'application/octet-stream');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (int) filesize($abs));
    $dl = isset($_GET['dl']) && (string) $_GET['dl'] === '1';
    $disposition = $dl ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode((string) ($found['name'] ?? basename($abs))) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($abs);
    exit;
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] customer attachment-download: ' . $e->getMessage());
    }
    http_response_code(500);
    exit('Server error');
}
