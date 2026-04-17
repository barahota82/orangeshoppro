</main>
<?php
if (function_exists('storefront_toolbar_state')) {
    extract(storefront_toolbar_state());
    $SF_NAV_PLACEMENT = 'dock';
    include __DIR__ . '/storefront_nav_cluster.php';
}
if (function_exists('storefront_tagline_cycle_messages')) {
    $sfTaglineJson = json_encode(storefront_tagline_cycle_messages(), JSON_UNESCAPED_UNICODE);
    ?>
<textarea id="storefront-tagline-json" hidden readonly class="storefront-tagline-json"><?php echo htmlspecialchars((string)$sfTaglineJson, ENT_QUOTES, 'UTF-8'); ?></textarea>
<?php } ?>
<script src="<?php echo htmlspecialchars(storefront_asset_url('/assets/js/lang.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(storefront_asset_url('/assets/js/app.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(storefront_asset_url('/assets/js/cart.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
