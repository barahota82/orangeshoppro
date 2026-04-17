<?php
require_once __DIR__ . '/config.php';
$channelSlug = current_channel_slug();
$lang = current_lang();
header('Location: /pages/home.php?channel=' . urlencode($channelSlug) . '&lang=' . urlencode($lang));
exit;
