/**
 * Mounts one shared translations panel per catalogue name field.
 * The page keeps parent, sort, active, locked slug, and its own save button.
 */
(function (global) {
    var apis = {};

    function mountOne(root, boot, idPrefix, onEnglish) {
        if (!root || !global.OrangeContentLocalePanel) return null;
        var api = global.OrangeContentLocalePanel.mount(root, {
            showSlug: false,
            showSort: false,
            showSave: false,
            debounceMs: 650,
            idPrefix: idPrefix,
            suggest: function (req) {
                var body = {
                    base_text: req.base_text,
                    known: req.known || {},
                    explicit: req.explicit || []
                };
                if (req.from_corrected_english) {
                    body.from_corrected_english = true;
                    body.corrected_english = req.corrected_english || '';
                }
                if (typeof global.postJSON !== 'function') {
                    return Promise.resolve({ success: false, suggestions: {} });
                }
                return global.postJSON('/admin/api/content_locale/suggest.php', body);
            },
            onEnglishInput: function (value) {
                if (typeof onEnglish === 'function') onEnglish(value);
            },
            onApplied: function (detail) {
                if (!detail || detail.rejected || detail.label === 'failed' || detail.label === 'rejected') return;
                if (detail.englishApplied !== true) return;
                var english = String(detail.english || '').trim();
                if (english === '') return;
                if (typeof onEnglish === 'function') onEnglish(english);
            },
            onBaseInput: function (value, base) {
                if (base === 'en' && typeof onEnglish === 'function') onEnglish(value);
            }
        });
        api.setRoles(boot.roles || {}, boot.active || []);
        api.openRecord({ id: 0, base_text: '', locales: {} });
        return api;
    }

    function bind(boot) {
        boot = boot || {};
        (boot.mounts || []).forEach(function (item) {
            var root = document.getElementById(item.rootId);
            apis[item.key] = mountOne(root, boot, item.idPrefix || '', item.onEnglish || null);
        });
    }

    function open(key, record) {
        if (apis[key]) apis[key].openRecord(record || { id: 0, base_text: '', locales: {} });
    }

    function reset(key) {
        open(key, { id: 0, base_text: '', locales: {} });
    }

    function merge(key) {
        if (!apis[key]) return {};
        return apis[key].getPayload();
    }

    function english(key) {
        var payload = merge(key);
        var locales = payload.locales || {};
        if (locales.en && locales.en.present && !locales.en.clear) return String(locales.en.text || '');
        var boot = global.UC_LOCALE_BOOT || global.PT_LOCALE_BOOT || {};
        if (boot.roles && boot.roles.base === 'en') return String(payload.base_text || '');
        return '';
    }

    global.OrangeCatalogNameLocale = { bind: bind, open: open, reset: reset, merge: merge, english: english };
}(typeof window !== 'undefined' ? window : globalThis));
