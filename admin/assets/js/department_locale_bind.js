(function () {
    var boot = window.DEPT_LOCALE_BOOT || {};
    var root = document.getElementById('dept-locale-app');
    if (!root || !window.OrangeContentLocalePanel) return;

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (res) {
            return res.text().then(function (text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    return { success: false, code: 'invalid_json' };
                }
            });
        });
    }

    var ui = window.OrangeContentLocalePanel.mount(root, {
        debounceMs: 600,
        suggest: function (req) {
            var body = {
                base_text: req.base_text,
                known: req.known,
                explicit: req.explicit,
                panel: window.OrangeContentLocalePanel.panelLocales(boot.roles, boot.active)
            };
            if (req.from_corrected_english) {
                body.corrected_english = req.corrected_english;
                body.previous_english = req.previous_english;
            }
            return post('/admin/api/departments/suggest.php', body);
        },
        onSave: function (payload) {
            var url = payload.record_id ? '/admin/api/departments/update.php' : '/admin/api/departments/save.php';
            if (payload.record_id) payload.id = payload.record_id;
            post(url, payload).then(function (res) {
                window.alert((res && res.message) || ((res && res.success) ? 'تم الحفظ' : 'فشل الحفظ'));
                if (res && res.success) window.location.reload();
            }).catch(function () {
                window.alert('فشل الاتصال بالخادم أثناء الحفظ');
            });
        }
    });
    ui.setRoles(boot.roles, boot.active);
    window.OrangeDeptLocaleUi = ui;
}());
