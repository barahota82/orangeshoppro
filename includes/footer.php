</main>
<?php
if (function_exists('storefront_toolbar_state')) {
    extract(storefront_toolbar_state());
    $SF_NAV_PLACEMENT = 'dock';
    include __DIR__ . '/storefront_nav_cluster.php';
}
?>
<script src="/assets/js/lang.js" defer></script>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/cart.js" defer></script>
</body>
</html>
