<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/document_public_token.php';
require_admin_api();

$docKind = trim((string) ($_REQUEST['doc_kind'] ?? ''));
$docId = (int) ($_REQUEST['doc_id'] ?? 0);

if (! orange_doc_public_token_kind_valid($docKind) || $docId <= 0) {
    json_response(['success' => false, 'message' => 'نوع المستند أو معرّفه غير صالح'], 422);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $token = orange_doc_public_token_ensure($pdo, $docKind, $docId);
    if ($token === null) {
        json_response(['success' => false, 'message' => 'تعذر إنشاء رابط المستند'], 500);
    }
    json_response([
        'success' => true,
        'token' => $token,
        'url' => orange_doc_public_absolute_url($token),
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر إنشاء رابط المستند');
}
