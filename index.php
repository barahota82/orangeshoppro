<?php
require_once __DIR__ . '/config.php';
$channelSlug = current_channel_slug();
$lang = current_lang();
header('Location: ' . storefront_url('home', $channelSlug, $lang));
exit;
