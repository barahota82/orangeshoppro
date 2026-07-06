<?php

require_once __DIR__ . '/../../../config.php';
require_admin_api();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'note' => 'Open this URL in browser. You must see JSON only. If you see PHP code, PHP is not running in this folder.',
], JSON_UNESCAPED_UNICODE);
