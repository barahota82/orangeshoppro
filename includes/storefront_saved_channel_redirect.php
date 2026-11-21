<?php

declare(strict_types=1);

/**
 * س32: سلطة الدخول للدولة (مسار/?country=/كوكي دولة).
 * لا يُعاد توجيه الزائر إلى ?channel= كسلطة دخول.
 */
?>
(function orangeStorefrontApplySavedCountry() {
        try {
            var params = new URLSearchParams(window.location.search || '');
            if (params.get('country') || params.get('channel')) {
                return;
            }
            var previewTok = <?php echo json_encode(ORANGE_STOREFRONT_PREVIEW_TOKEN, JSON_UNESCAPED_UNICODE); ?>;
            var previewQ = params.get('sf_preview');
            if (previewTok !== '' && previewQ !== null && previewQ === previewTok) {
                return;
            }
            if (<?php echo !empty($orangeSfSessionPreviewBypass) ? 'true' : 'false'; ?>) {
                return;
            }
            // إن وُجد كوكي دولة صالح أو مسار دولة قصير — لا تدخل قناة.
            var ctryName = <?php echo json_encode(orange_storefront_country_cookie_name(), JSON_UNESCAPED_UNICODE); ?> + '=';
            var parts = (document.cookie || '').split(';');
            for (var i = 0; i < parts.length; i++) {
                var p = parts[i].replace(/^\s+/, '');
                if (p.indexOf(ctryName) === 0) {
                    var cc = p.slice(ctryName.length).replace(/[^a-z0-9]/gi, '').toLowerCase();
                    if (cc) {
                        return;
                    }
                }
            }
            var __sfBase = <?php echo json_encode(PUBLIC_BASE_PATH, JSON_UNESCAPED_UNICODE); ?> || '';
            var rawPath = window.location.pathname || '';
            var path = rawPath;
            if (__sfBase && path.indexOf(__sfBase) === 0) {
                path = path.slice(__sfBase.length) || '/';
            }
            if (/^\/[a-z]{2,3}(?:-(ar|hi|ph))?(?:\/|$)/i.test(path)) {
                return;
            }
            // لا استعادة channel كسلطة دخول (س32 / C7).
        } catch (e) {}
    })();
