<?php
declare(strict_types=1);
?>
(function orangeStorefrontApplySavedChannel() {
        try {
            var params = new URLSearchParams(window.location.search || '');
            if (params.get('channel')) {
                return;
            }
            var previewTok = <?php echo json_encode(ORANGE_STOREFRONT_PREVIEW_TOKEN, JSON_UNESCAPED_UNICODE); ?>;
            var previewQ = params.get('sf_preview');
            if (previewTok !== '' && previewQ !== null && previewQ === previewTok) {
                return;
            }
            if (<?php echo $orangeSfSessionPreviewBypass ? 'true' : 'false'; ?>) {
                return;
            }
            var allowed = window.ORANGE_SF_VALID_SLUGS || {};
            var restoreLang = <?php echo json_encode(preg_match('/^[a-z]{2,8}$/', strtolower((string) $lang)) ? strtolower((string) $lang) : '', JSON_UNESCAPED_UNICODE); ?>;
            var restoreMap = <?php echo json_encode(orange_storefront_customer_lang_codes_by_channel(db()), JSON_UNESCAPED_UNICODE); ?>;
            var shortPathRe = new RegExp('^\\/(' + <?php echo json_encode($_sfPathAlt, JSON_UNESCAPED_UNICODE); ?> + ')(-ar|-hi|-ph)?(\\/.*)?$', 'i');
            var accountCh = <?php echo json_encode($orangeAccountChannelForJs, JSON_UNESCAPED_UNICODE); ?>;
            function orangeReadSfChannelCookie() {
                var name = '<?php echo htmlspecialchars(orange_storefront_channel_cookie_name(), ENT_QUOTES, 'UTF-8'); ?>=';
                var parts = (document.cookie || '').split(';');
                for (var i = 0; i < parts.length; i++) {
                    var p = parts[i].replace(/^\s+/, '');
                    if (p.indexOf(name) === 0) {
                        return p.slice(name.length).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
                    }
                }
                return '';
            }
            function orangeReadSfLangCookie() {
                var name = '<?php echo htmlspecialchars(orange_storefront_lang_cookie_name(), ENT_QUOTES, 'UTF-8'); ?>=';
                var parts = (document.cookie || '').split(';');
                for (var i = 0; i < parts.length; i++) {
                    var p = parts[i].replace(/^\s+/, '');
                    if (p.indexOf(name) === 0) {
                        return p.slice(name.length).toLowerCase().replace(/[^a-z]/g, '');
                    }
                }
                return '';
            }
            function orangeRestoreLangFor(slug) {
                var list = (restoreMap && restoreMap[slug]) || [];
                if (restoreLang && list.indexOf(restoreLang) !== -1) {
                    return restoreLang;
                }
                var savedLang = orangeReadSfLangCookie();
                if (savedLang && list.indexOf(savedLang) !== -1) {
                    return savedLang;
                }
                return '';
            }
            var savedCh = '';
            var accKey = accountCh ? String(accountCh).toLowerCase() : '';
            if (accKey && allowed[accKey]) {
                savedCh = String(accountCh).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
            } else {
                var rawLs = localStorage.getItem('orange_storefront_channel') || '';
                savedCh = String(rawLs).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
                if (!savedCh || !allowed[savedCh]) {
                    var rawCk = orangeReadSfChannelCookie();
                    savedCh = String(rawCk).replace(/[^a-z0-9\-]/gi, '').toLowerCase();
                }
            }
            if (!savedCh || !allowed[savedCh]) {
                return;
            }
            var __sfBase = <?php echo json_encode(PUBLIC_BASE_PATH, JSON_UNESCAPED_UNICODE); ?> || '';
            var rawPath = window.location.pathname || '';
            var path = rawPath;
            if (__sfBase && path.indexOf(__sfBase) === 0) {
                path = path.slice(__sfBase.length) || '/';
            }
            if (!path || path.charAt(0) !== '/') {
                path = '/' + (path || '');
            }
            function orangeParseShortStorePath(pathname) {
                var m = String(pathname).match(shortPathRe);
                if (!m) { return null; }
                var seg = m[1].toLowerCase();
                var pmap = window.ORANGE_SF_PATH_TO_SLUG || {};
                var ch = pmap[seg];
                if (!ch) { return null; }
                var tail = m[3] || '';
                return { ch: ch, tail: tail };
            }
            var short = orangeParseShortStorePath(path);
            if (short && short.ch && allowed[short.ch]) {
                return;
            }
            if (path.indexOf('/pages/') !== -1) {
                params.set('channel', savedCh);
                var keepLang = orangeRestoreLangFor(savedCh);
                if (keepLang) {
                    params.set('lang', keepLang);
                } else {
                    params.delete('lang');
                }
                var qs = params.toString();
                window.location.replace(rawPath + (qs ? '?' + qs : ''));
                return;
            }
        } catch (e) {}
    })();
