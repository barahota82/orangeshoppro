<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/storefront_account.php';

$pdo = db();
$channelSlug = orange_storefront_valid_channel_slug($pdo, current_channel_slug());
$lang = current_lang();
header('Location: ' . storefront_url('home', $channelSlug, $lang));
exit;
