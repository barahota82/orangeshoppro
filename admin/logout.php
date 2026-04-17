<?php
require_once __DIR__ . '/../config.php';
admin_logout();
header('Location: /admin/login.php');
exit;
