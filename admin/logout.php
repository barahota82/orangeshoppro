<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/upload_paths.php';
admin_logout();
header('Location: ' . storefront_public_path('/admin/login.php'));
exit;
