<?php

declare(strict_types=1);

/**
 * أزرار قفل/فك على شاشة المصدر — يستدعى مرة واحدة للسكربت المشترك.
 */
function orange_edit_lock_ui_script_once(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
<script>
(function () {
    if (window.OrangeEditLock && window.OrangeEditLock.bind) return;
    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json(); });
    }
    window.OrangeEditLock = {
        instances: {},
        bind: function (cfg) {
            var prefix = cfg.prefix || 'el';
            var docKind = cfg.docKind || '';
            var getDocKind = typeof cfg.getDocKind === 'function' ? cfg.getDocKind : function () { return docKind; };
            var countryId = parseInt(String(cfg.countryId || '0'), 10) || 0;
            var getEntityId = typeof cfg.getEntityId === 'function' ? cfg.getEntityId : function () { return 0; };
            var onChange = typeof cfg.onLockedChange === 'function' ? cfg.onLockedChange : null;
            var wrap = document.getElementById(prefix + '_edit_lock_wrap');
            var btnLock = document.getElementById(prefix + '_edit_lock_btn_lock');
            var btnUnlock = document.getElementById(prefix + '_edit_lock_btn_unlock');
            var badge = document.getElementById(prefix + '_edit_lock_badge');
            if (!wrap || !docKind) return { refresh: function () {} };
            function setVisible(show) {
                wrap.hidden = !show;
                wrap.style.display = show ? '' : 'none';
            }
            function paint(locked, hasEntity) {
                if (!hasEntity) {
                    setVisible(false);
                    return;
                }
                setVisible(true);
                if (badge) {
                    badge.textContent = locked ? 'مقفول — التعديل محظور' : 'مفتوح للتعديل';
                    badge.className = locked ? 'edit-lock-badge edit-lock-badge--locked' : 'edit-lock-badge edit-lock-badge--open';
                }
                if (btnLock) btnLock.hidden = locked;
                if (btnUnlock) btnUnlock.hidden = !locked;
                if (onChange) onChange(locked);
            }
            function refresh() {
                var eid = parseInt(String(getEntityId() || '0'), 10) || 0;
                if (eid <= 0) {
                    paint(false, false);
                    return;
                }
                var q = '/admin/api/edit-lock/status.php?doc_kind=' + encodeURIComponent(getDocKind())
                    + '&entity_id=' + encodeURIComponent(String(eid));
                if (countryId > 0) q += '&country_id=' + encodeURIComponent(String(countryId));
                fetch(q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        paint(!!(res && res.is_locked), true);
                    })
                    .catch(function () { paint(false, true); });
            }
            function toggle(lock) {
                var eid = parseInt(String(getEntityId() || '0'), 10) || 0;
                if (eid <= 0) return;
                postJson('/admin/api/edit-lock/toggle.php', {
                    doc_kind: getDocKind(),
                    entity_id: eid,
                    country_id: countryId > 0 ? countryId : undefined,
                    lock: !!lock
                }).then(function (res) {
                    if (!res || !res.success) {
                        alert((res && res.message) || 'تعذر تغيير حالة القفل');
                        return;
                    }
                    refresh();
                }).catch(function (e) { alert(e.message || String(e)); });
            }
            if (btnLock && !btnLock._elBound) {
                btnLock._elBound = true;
                btnLock.addEventListener('click', function () { toggle(true); });
            }
            if (btnUnlock && !btnUnlock._elBound) {
                btnUnlock._elBound = true;
                btnUnlock.addEventListener('click', function () { toggle(false); });
            }
            var api = { refresh: refresh };
            window.OrangeEditLock.instances[prefix] = api;
            refresh();
            return api;
        }
    };
})();
</script>
<style>
.edit-lock-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:8px 12px; margin:0 0 12px; }
.edit-lock-badge { font-size:0.85rem; font-weight:600; }
.edit-lock-badge--locked { color:#b45309; }
.edit-lock-badge--open { color:#047857; }
</style>
    <?php
}

/**
 * @param array{prefix:string,doc_kind:string,country_id?:int,class?:string} $opts
 */
function orange_edit_lock_ui_toolbar(array $opts): void
{
    $prefix = preg_replace('/[^a-z0-9_]/i', '', (string) ($opts['prefix'] ?? 'el')) ?: 'el';
    $docKind = trim((string) ($opts['doc_kind'] ?? ''));
    $class = trim((string) ($opts['class'] ?? 'edit-lock-toolbar jv-print-hide'));
    orange_edit_lock_ui_script_once();
    ?>
<div id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_edit_lock_wrap"
     class="<?php echo htmlspecialchars($class, ENT_QUOTES, 'UTF-8'); ?>"
     data-doc-kind="<?php echo htmlspecialchars($docKind, ENT_QUOTES, 'UTF-8'); ?>"
     hidden>
    <button type="button" class="btn-secondary" id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_edit_lock_btn_lock">قفل التعديل</button>
    <button type="button" class="btn-secondary" id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_edit_lock_btn_unlock" hidden>فك القفل</button>
    <span id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_edit_lock_badge" class="edit-lock-badge"></span>
</div>
    <?php
}
