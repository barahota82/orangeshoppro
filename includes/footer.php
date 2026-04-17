</main>
<?php if (function_exists('storefront_tagline_rotate_messages')): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var lines = <?php echo json_encode(storefront_tagline_rotate_messages(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var els = document.querySelectorAll('.storefront-rotating-tagline');
    if (!lines.length || !els.length) return;
    var i = 0;
    setInterval(function () {
        i = (i + 1) % lines.length;
        var t = lines[i];
        els.forEach(function (el) { el.textContent = t; });
    }, 5000);
});
</script>
<?php endif; ?>
<script src="/assets/js/lang.js" defer></script>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/cart.js" defer></script>
</body>
</html>
