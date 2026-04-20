</main>
<?php
if (function_exists('storefront_toolbar_state')) {
    extract(storefront_toolbar_state());
    $SF_NAV_PLACEMENT = 'dock';
    $SF_NAV_CLUSTER_PART = 'dock';
    include __DIR__ . '/storefront_nav_cluster.php';
    ?>
<div id="orangeInstallModal" class="orange-install-modal" hidden>
    <div class="orange-install-modal__backdrop" tabindex="-1"></div>
    <div class="orange-install-modal__panel" role="dialog" aria-modal="true" aria-labelledby="orangeInstallModalTitle">
        <h2 id="orangeInstallModalTitle" class="orange-install-modal__title"></h2>
        <p id="orangeInstallModalIntro" class="orange-install-modal__intro"></p>
        <div id="orangeInstallModalSteps" class="orange-install-modal__steps"></div>
        <button type="button" class="btn orange-install-modal__ok" id="orangeInstallModalOk"></button>
    </div>
</div>
    <?php
}
if (function_exists('storefront_tagline_cycle_messages')) {
    $sfTaglineJson = json_encode(storefront_tagline_cycle_messages(), JSON_UNESCAPED_UNICODE);
    ?>
<textarea id="storefront-tagline-json" hidden readonly class="storefront-tagline-json"><?php echo htmlspecialchars((string)$sfTaglineJson, ENT_QUOTES, 'UTF-8'); ?></textarea>
<?php } ?>
<script src="<?php echo htmlspecialchars(storefront_asset_url('/assets/js/lang.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(storefront_asset_url('/assets/js/input-constraints.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(storefront_asset_url('/assets/js/app.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<script src="<?php echo htmlspecialchars(storefront_asset_url('/assets/js/cart.js'), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</body>
</html>
