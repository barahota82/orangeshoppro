<?php

declare(strict_types=1);

require_once __DIR__ . '/admin_permissions.php';

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
            headers: typeof orangeAdminCountryHeaders === 'function'
                ? orangeAdminCountryHeaders({ 'Content-Type': 'application/json', Accept: 'application/json' })
                : { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json(); });
    }
    function capsForCfg(cfg) {
        if (cfg.canLock === false || cfg.canUnlock === false || cfg.canLock === true || cfg.canUnlock === true) {
            return {
                can_lock: cfg.canLock !== false,
                can_unlock: cfg.canUnlock !== false
            };
        }
        var pg = cfg.page || window.ORANGE_ADMIN_PAGE || 'dashboard';
        if (typeof orangeAdminCaps === 'function') {
            var c = orangeAdminCaps(pg);
            return { can_lock: !!c.can_lock, can_unlock: !!c.can_unlock };
        }
        return { can_lock: true, can_unlock: true };
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
            var permCaps = capsForCfg(cfg);
            var wrap = document.getElementById(prefix + '_edit_lock_wrap');
            var chk = document.getElementById(prefix + '_edit_lock_chk');
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
                if (chk) {
                    chk.checked = !!locked;
                    /* لقفل مستند مفتوح يلزم can_lock؛ لفك مستند مقفول يلزم can_unlock */
                    chk.disabled = locked ? !permCaps.can_unlock : !permCaps.can_lock;
                }
                if (badge) {
                    badge.textContent = locked ? 'مقفول — التعديل محظور' : 'مفتوح للتعديل';
                    badge.className = locked ? 'edit-lock-badge edit-lock-badge--locked' : 'edit-lock-badge edit-lock-badge--open';
                }
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
                fetch(q, {
                    credentials: 'same-origin',
                    headers: typeof orangeAdminCountryHeaders === 'function'
                        ? orangeAdminCountryHeaders({ Accept: 'application/json' })
                        : { Accept: 'application/json' },
                    cache: 'no-store'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        paint(!!(res && res.is_locked), true);
                    })
                    .catch(function () { paint(false, true); });
            }
            function applyToggle(lock) {
                var eid = parseInt(String(getEntityId() || '0'), 10) || 0;
                if (eid <= 0) { refresh(); return; }
                postJson('/admin/api/edit-lock/toggle.php', {
                    doc_kind: getDocKind(),
                    entity_id: eid,
                    country_id: countryId > 0 ? countryId : undefined,
                    lock: !!lock
                }).then(function (res) {
                    if (!res || !res.success) {
                        alert((res && res.message) || 'تعذر تغيير حالة القفل');
                        refresh();
                        return;
                    }
                    refresh();
                }).catch(function (e) { alert(e.message || String(e)); refresh(); });
            }
            if (chk && !chk._elBound) {
                chk._elBound = true;
                chk.addEventListener('change', function () {
                    var wantLock = chk.checked;
                    if (wantLock && !permCaps.can_lock) {
                        alert('لا تملك صلاحية قفل');
                        chk.checked = false;
                        return;
                    }
                    if (!wantLock && !permCaps.can_unlock) {
                        alert('لا تملك صلاحية فك القفل');
                        chk.checked = true;
                        return;
                    }
                    var confirmMsg = wantLock
                        ? 'تأكيد قفل هذا المستند؟ سيُمنع التعديل والحذف حتى فك القفل.'
                        : 'تأكيد فك قفل هذا المستند؟ سيصبح قابلاً للتعديل.';
                    if (!window.confirm(confirmMsg)) {
                        chk.checked = !wantLock;
                        return;
                    }
                    applyToggle(wantLock);
                });
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
.edit-lock-check { display:flex; align-items:center; gap:8px; cursor:pointer; }
.edit-lock-check input[type="checkbox"]:disabled { cursor:default; }
.edit-lock-badge { font-size:0.85rem; font-weight:600; }
.edit-lock-badge--locked { color:#b45309; }
.edit-lock-badge--open { color:#047857; }
</style>
    <?php
}

/**
 * @param array{
 *   prefix:string,
 *   doc_kind:string,
 *   country_id?:int,
 *   class?:string,
 *   page?:string,
 *   can_lock?:bool,
 *   can_unlock?:bool,
 *   admin?:array,
 *   pdo?:PDO
 * } $opts
 */
function orange_edit_lock_ui_toolbar(array $opts): void
{
    $prefix = preg_replace('/[^a-z0-9_]/i', '', (string) ($opts['prefix'] ?? 'el')) ?: 'el';
    $docKind = trim((string) ($opts['doc_kind'] ?? ''));
    $class = trim((string) ($opts['class'] ?? 'edit-lock-toolbar jv-print-hide'));
    $note = trim((string) ($opts['note'] ?? ''));
    orange_edit_lock_ui_script_once();
    ?>
<div id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_edit_lock_wrap"
     class="<?php echo htmlspecialchars($class, ENT_QUOTES, 'UTF-8'); ?>"
     data-doc-kind="<?php echo htmlspecialchars($docKind, ENT_QUOTES, 'UTF-8'); ?>"
     hidden>
    <label class="edit-lock-check">
        <input type="checkbox" id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_edit_lock_chk">
        <span><strong>قيد مغلق</strong><?php echo $note !== '' ? ' — ' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') : ''; ?></span>
    </label>
    <span id="<?php echo htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8'); ?>_edit_lock_badge" class="edit-lock-badge"></span>
</div>
    <?php
}
