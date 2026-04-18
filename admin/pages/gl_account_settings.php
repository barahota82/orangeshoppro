<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/gl_settings.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$flat = orange_accounts_flat($pdo);
$tree = orange_accounts_build_tree($flat);
$firstId = $flat !== [] ? (int) $flat[0]['id'] : 0;

$byId = [];
foreach ($flat as $a) {
    $byId[(int) $a['id']] = $a;
}

$current = [];
if (orange_table_exists($pdo, 'orange_gl_account_settings')) {
    $rows = $pdo->query('SELECT setting_key, account_id FROM orange_gl_account_settings')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $current[(string) $r['setting_key']] = (int) $r['account_id'];
    }
}

$rowTitles = orange_gl_setting_row_short_labels();
$keyHints = orange_gl_setting_key_labels();
$orderedKeys = orange_gl_settings_form_key_order();
$labelsKeys = array_keys($keyHints);
$orderedKeys = array_values(array_filter($orderedKeys, static function ($k) use ($labelsKeys) {
    return in_array($k, $labelsKeys, true);
}));
?>
<div class="gl-auto-page" dir="rtl">
    <h1 class="gl-auto-page__title">حسابات القيود التلقائية</h1>

    <div class="gl-auto-shell__body" dir="ltr">
        <aside class="coa-shell__tree card coa-tree-card gl-auto-tree">
            <h3 class="card-title coa-tree-card__title">شجرة الحسابات</h3>
            <div class="coa-tree-search">
                <input type="search" id="gl_coa_tree_search" class="coa-tree-search__input" placeholder="بحث في الشجرة…" autocomplete="off" dir="rtl">
            </div>
            <a class="btn-secondary gl-auto-link-coa" href="/admin/index.php?page=chart_of_accounts">فتح الدليل المحاسبي</a>
            <div class="coa-tree-scroll" id="gl_coa_tree_root" role="tree">
                <?php if ($tree === []): ?>
                    <p class="muted">لا توجد حسابات. عرّف الدليل أولاً.</p>
                <?php else: ?>
                    <?php orange_render_coa_tree($tree, 0, $flat, 0); ?>
                <?php endif; ?>
            </div>
        </aside>

        <div class="gl-auto-main" dir="rtl">
            <div class="card gl-auto-form-card">
                <h3 class="card-title">الحساب من الدليل المحاسبي</h3>
                <p class="muted gl-auto-hint">
                    أدخل <strong>كود الحساب</strong> فيُكمّل الاسم تلقائياً، أو اضغط <strong>البحث</strong> لعرض <strong>الحسابات الفرعية فقط</strong> واختر من القائمة.
                </p>
                <div class="table-wrap gl-settings-table-wrap">
                    <table class="gl-settings-table">
                        <thead>
                            <tr>
                                <th class="gl-th-label">البند</th>
                                <th class="gl-th-code">كود الحساب</th>
                                <th class="gl-th-name">اسم الحساب</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orderedKeys as $key):
                                $aid = (int) ($current[$key] ?? 0);
                                $code = $aid > 0 ? (string) ($byId[$aid]['code'] ?? '') : '';
                                $name = $aid > 0 ? (string) ($byId[$aid]['name'] ?? '') : '';
                                $title = htmlspecialchars($keyHints[$key] ?? '', ENT_QUOTES, 'UTF-8');
                                $short = htmlspecialchars($rowTitles[$key] ?? $key, ENT_QUOTES, 'UTF-8');
                                ?>
                            <tr data-gl-key="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" data-account-id="<?php echo $aid; ?>" title="<?php echo $title; ?>">
                                <td class="gl-td-label"><?php echo $short; ?></td>
                                <td class="gl-td-code">
                                    <input type="text" class="gl-inp-code" dir="ltr" autocomplete="off" value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>" aria-label="كود الحساب">
                                </td>
                                <td class="gl-td-name">
                                    <div class="gl-name-row">
                                        <button type="button" class="gl-search-btn" title="بحث — حسابات فرعية فقط" aria-label="بحث">🔍</button>
                                        <input type="text" class="gl-inp-name" readonly tabindex="-1" value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" aria-label="اسم الحساب">
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="gl-auto-actions">
                    <button type="button" id="gl_btn_save">حفظ الربط</button>
                    <a class="btn-secondary" href="/admin/index.php?page=chart_of_accounts">الدليل المحاسبي</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="gl-pick-modal" id="gl_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="gl_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="gl_pick_title">
        <h3 id="gl_pick_title" class="gl-pick-modal__title">اختيار حساب فرعي</h3>
        <input type="search" id="gl_pick_q" class="gl-pick-modal__search" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="gl_pick_list"></ul>
        <button type="button" class="btn-secondary" id="gl_pick_close">إغلاق</button>
    </div>
</div>

<script>
(function () {
    var pickModal = document.getElementById('gl_pick_modal');
    var pickList = document.getElementById('gl_pick_list');
    var pickQ = document.getElementById('gl_pick_q');
    var pickBackdrop = document.getElementById('gl_pick_backdrop');
    var pickClose = document.getElementById('gl_pick_close');
    var activePickKey = null;
    var searchTimer = null;

    function glFillRow(tr, acc) {
        if (!tr || !acc) {
            return;
        }
        tr.setAttribute('data-account-id', String(acc.id));
        var c = tr.querySelector('.gl-inp-code');
        var n = tr.querySelector('.gl-inp-name');
        if (c) {
            c.value = acc.code || '';
        }
        if (n) {
            n.value = acc.name || '';
        }
    }
    function glClearRow(tr) {
        tr.setAttribute('data-account-id', '0');
        var c = tr.querySelector('.gl-inp-code');
        var n = tr.querySelector('.gl-inp-name');
        if (c) {
            c.value = '';
        }
        if (n) {
            n.value = '';
        }
    }
    function openPick(key) {
        activePickKey = key;
        pickModal.hidden = false;
        pickModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        pickQ.value = '';
        pickList.innerHTML = '';
        glPickLoad('');
        pickQ.focus();
    }
    function closePick() {
        activePickKey = null;
        pickModal.hidden = true;
        pickModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function glPickLoad(q) {
        var url = '/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    pickList.innerHTML = '<li class="gl-pick-empty">' + (data.message || 'تعذر التحميل') + '</li>';
                    return;
                }
                var accs = data.accounts || [];
                if (accs.length === 0) {
                    pickList.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
                    return;
                }
                pickList.innerHTML = '';
                accs.forEach(function (a) {
                    var li = document.createElement('li');
                    li.className = 'gl-pick-item';
                    var code = a.code || '';
                    var label = (code ? code + ' — ' : '') + (a.name || '');
                    li.textContent = label;
                    li.setAttribute('role', 'button');
                    li.tabIndex = 0;
                    li.addEventListener('click', function () {
                        if (!activePickKey) {
                            return;
                        }
                        var tr = document.querySelector('tr[data-gl-key="' + activePickKey + '"]');
                        if (tr) {
                            glFillRow(tr, { id: a.id, code: code, name: a.name || '' });
                        }
                        closePick();
                    });
                    li.addEventListener('keydown', function (ev) {
                        if (ev.key === 'Enter' || ev.key === ' ') {
                            ev.preventDefault();
                            li.click();
                        }
                    });
                    pickList.appendChild(li);
                });
            })
            .catch(function (e) {
                pickList.innerHTML = '<li class="gl-pick-empty">' + (e.message || String(e)) + '</li>';
            });
    }

    document.querySelectorAll('tr[data-gl-key]').forEach(function (tr) {
        var codeInp = tr.querySelector('.gl-inp-code');
        if (codeInp) {
            codeInp.addEventListener('change', function () {
                var raw = codeInp.value.trim();
                if (!raw) {
                    glClearRow(tr);
                    return;
                }
                fetch('/admin/api/accounts/lookup-by-code.php?code=' + encodeURIComponent(raw), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            alert(data.message || 'الكود غير صالح');
                            glClearRow(tr);
                            return;
                        }
                        glFillRow(tr, data.account);
                    })
                    .catch(function (e) { alert(e.message || String(e)); });
            });
        }
        var btn = tr.querySelector('.gl-search-btn');
        if (btn) {
            btn.addEventListener('click', function () {
                var key = tr.getAttribute('data-gl-key');
                if (key) {
                    openPick(key);
                }
            });
        }
    });

    pickQ.addEventListener('input', function () {
        if (searchTimer) {
            clearTimeout(searchTimer);
        }
        searchTimer = setTimeout(function () {
            glPickLoad(pickQ.value.trim());
        }, 280);
    });
    pickBackdrop.addEventListener('click', closePick);
    pickClose.addEventListener('click', closePick);

    document.getElementById('gl_btn_save').addEventListener('click', function () {
        var settings = {};
        document.querySelectorAll('tr[data-gl-key]').forEach(function (tr) {
            var k = tr.getAttribute('data-gl-key');
            if (!k) {
                return;
            }
            var id = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
            settings[k] = id;
        });
        postJSON('/admin/api/settings/gl-accounts.php', { action: 'save', settings: settings }).then(function (res) {
            alert(res.message || (res.success ? 'تم' : 'فشل'));
            if (res.success) {
                location.reload();
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    });

    /* بحث الشجرة (نفس منطق الدليل) */
    var treeEl = document.getElementById('gl_coa_tree_root');
    var searchInp = document.getElementById('gl_coa_tree_search');
    if (treeEl && searchInp) {
        function liHasMatchingDescendant(li, q) {
            var lab = li.querySelector(':scope > .coa-tree-label');
            if (lab && lab.textContent.toLowerCase().indexOf(q) >= 0) {
                return true;
            }
            var ul = li.querySelector(':scope > .coa-tree-list');
            if (!ul) {
                return false;
            }
            var children = ul.querySelectorAll(':scope > .coa-tree-node');
            for (var i = 0; i < children.length; i++) {
                if (liHasMatchingDescendant(children[i], q)) {
                    return true;
                }
            }
            return false;
        }
        function applyTreeFilter(raw) {
            var q = (raw || '').trim().toLowerCase();
            var nodes = treeEl.querySelectorAll('.coa-tree-node');
            if (!q) {
                nodes.forEach(function (li) { li.style.display = ''; });
                return;
            }
            nodes.forEach(function (li) {
                li.style.display = liHasMatchingDescendant(li, q) ? '' : 'none';
            });
        }
        searchInp.addEventListener('input', function () {
            applyTreeFilter(this.value);
        });
    }
})();
</script>
