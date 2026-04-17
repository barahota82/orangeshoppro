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
<script src="/assets/js/lang.js" defer></script>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/cart.js" defer></script>
</body>
</html>
