<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/payments/payment_core.php';
require_once __DIR__ . '/../../../includes/upload_paths.php';

require_admin_api();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    exit('Method not allowed');
}

try {
    $pdo = db();
    orange_payments_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'payment_transactions')
        || !orange_table_has_column($pdo, 'payment_transactions', 'proof_file')) {
        http_response_code(503);
        exit('Service unavailable');
    }

    $orderId = (int) ($_GET['order_id'] ?? 0);
    $txnId = (int) ($_GET['txn_id'] ?? 0);

    if ($txnId > 0) {
        $st = $pdo->prepare(
            'SELECT id, order_id, proof_file FROM payment_transactions WHERE id = ? LIMIT 1'
        );
        $st->execute([$txnId]);
    } elseif ($orderId > 0) {
        $st = $pdo->prepare(
            'SELECT id, order_id, proof_file FROM payment_transactions
             WHERE order_id = ? AND proof_file <> \'\'
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$orderId]);
    } else {
        http_response_code(400);
        exit('Bad request');
    }

    $txn = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($txn)) {
        http_response_code(404);
        exit('Not found');
    }

    $resolvedOrderId = (int) ($txn['order_id'] ?? 0);
    if ($resolvedOrderId <= 0) {
        http_response_code(404);
        exit('Not found');
    }
    if ($orderId > 0 && $resolvedOrderId !== $orderId) {
        http_response_code(404);
        exit('Not found');
    }

    try {
        orange_admin_assert_entity_country($pdo, 'orders', $resolvedOrderId);
    } catch (RuntimeException $e) {
        http_response_code(403);
        exit('Forbidden');
    }

    $proofFile = trim((string) ($txn['proof_file'] ?? ''));
    if ($proofFile === '' || str_contains($proofFile, '..')) {
        http_response_code(404);
        exit('Not found');
    }

    $baseName = basename(str_replace('\\', '/', $proofFile));
    if ($baseName === '' || $baseName === '.' || $baseName === '..') {
        http_response_code(404);
        exit('Not found');
    }

    $proofDir = orange_payment_proof_dir();
    $abs = $proofDir . DIRECTORY_SEPARATOR . $baseName;
    $dirReal = @realpath($proofDir);
    $fileReal = @realpath($abs);
    if ($dirReal === false || $fileReal === false || !is_file($fileReal) || !is_readable($fileReal)) {
        http_response_code(404);
        exit('Not found');
    }
    $dirPrefix = $dirReal . DIRECTORY_SEPARATOR;
    if (!str_starts_with($fileReal, $dirPrefix)) {
        http_response_code(404);
        exit('Not found');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileReal);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    if (!is_string($mime) || !in_array($mime, $allowed, true)) {
        http_response_code(415);
        exit('Unsupported type');
    }

    $dl = isset($_GET['dl']) && (string) $_GET['dl'] === '1';
    $disposition = $dl ? 'attachment' : 'inline';
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName) ?: 'proof';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($fileReal));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Content-Disposition: ' . $disposition . '; filename="' . $ascii . '"');
    readfile($fileReal);
    exit;
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] payment proof-download: ' . $e->getMessage());
    }
    http_response_code(500);
    exit('Server error');
}
