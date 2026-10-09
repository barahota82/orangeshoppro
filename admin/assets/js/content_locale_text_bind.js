/**
 * Mounts the shared translations panel for a text field with no slug and no sort.
 * The page keeps its own save button and merges getPayload().
 */
(function (global) {
    function mount(root, options) {
        options = options || {};
        if (!root || !global.OrangeContentLocalePanel) {
            return null;
        }
        var api = global.OrangeContentLocalePanel.mount(root, {
            showSlug: false,
            showSort: false,
            showSave: false,
            debounceMs: options.debounceMs || 650,
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
            }
        });
        api.setRoles(options.roles || {}, options.active || []);
        api.openRecord(options.record || { id: 0, base_text: '', locales: {} });
        return api;
    }

    global.OrangeContentLocaleTextBind = { mount: mount };
}(typeof window !== 'undefined' ? window : globalThis));
