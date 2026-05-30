<?php

declare(strict_types=1);

/** @deprecated — استبدل بـ edit_lock (GAP-ACC-14). */
require_once __DIR__ . '/../../config.php';
header(
    'Location: ' . storefront_public_path('/admin/index.php?page=edit_lock'),
    true,
    302
);
exit;
