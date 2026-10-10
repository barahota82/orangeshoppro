/**
 * Mounts the shared translations panel on the four product text groups.
 * No product slug is added. Legacy inputs stay in the page for the previous path.
 */
(function (global) {
    var apis = {};
    var columns = {};

    function mountOne(root, boot, item) {
        if (!root || !global.OrangeContentLocalePanel) return null;
        var api = global.OrangeContentLocalePanel.mount(root, {
            showSlug: false,
            showSort: false,
            showSave: false,
            multiline: item.multiline === true,
            maxLength: item.maxLength || 0,
            debounceMs: 650,
            idPrefix: item.idPrefix || '',
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
            onBaseInput: refreshProductSurface,
            onEnglishInput: refreshProductSurface,
            onApplied: refreshProductSurface
        });
        api.setRoles(boot.roles || {}, boot.active || []);
        api.openRecord({ id: 0, base_text: '', locales: {} });
        return api;
    }

    function refreshProductSurface() {
        if (typeof global.orangeRefreshSeoEffectivePreview === 'function') {
            global.orangeRefreshSeoEffectivePreview();
        }
        if (typeof global.orangeScheduleProductCardPreviewRefresh === 'function') {
            global.orangeScheduleProductCardPreviewRefresh();
        }
        if (typeof global.orangeApplyProductWizardActionButtons === 'function') {
            global.orangeApplyProductWizardActionButtons();
        }
    }

    function bind(boot) {
        boot = boot || {};
        columns = boot.columns || {};
        (boot.mounts || []).forEach(function (item) {
            var root = document.getElementById(item.rootId);
            apis[item.key] = mountOne(root, boot, item);
        });
    }

    function openAll(view) {
        view = view || {};
        Object.keys(apis).forEach(function (key) {
            var state = view[key] || { id: 0, base_text: '', locales: {} };
            if (apis[key]) apis[key].openRecord(state);
        });
        refreshProductSurface();
    }

    function reset() {
        openAll({});
    }

    function merge(key) {
        if (!apis[key]) return { base_text: '', locales: {} };
        return apis[key].getPayload();
    }

    function bundle() {
        var out = {};
        Object.keys(apis).forEach(function (key) {
            out[key] = merge(key);
        });
        return out;
    }

    function setValue(id, value) {
        var el = document.getElementById(id);
        if (el) el.value = value == null ? '' : String(value);
    }

    function syncLegacy() {
        var boot = global.PRODUCT_LOCALE_BOOT || {};
        var base = boot.roles && boot.roles.base ? boot.roles.base : '';
        Object.keys(apis).forEach(function (key) {
            var payload = merge(key);
            var cols = columns[key] || {};
            if (base && cols[base]) setValue(cols[base], payload.base_text || '');
            var locales = payload.locales || {};
            Object.keys(cols).forEach(function (code) {
                if (code === base) return;
                var row = locales[code];
                if (!row || !row.present) return;
                setValue(cols[code], row.clear ? '' : (row.text || ''));
            });
        });
    }

    global.OrangeProductContentLocale = {
        bind: bind,
        openAll: openAll,
        reset: reset,
        merge: merge,
        bundle: bundle,
        syncLegacy: syncLegacy
    };
}(typeof window !== 'undefined' ? window : globalThis));
