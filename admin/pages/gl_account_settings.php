<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/gl_settings.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$accountsRows = $pdo->query(
    'SELECT id, name, code FROM accounts ORDER BY COALESCE(code, \'\'), name ASC'
)->fetchAll(PDO::FETCH_ASSOC);
$byId = [];
foreach ($accountsRows as $a) {
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
    var glPickSeq = 0;

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
    /** كود رئيسي أو غير صالح: يبقى الكود المكتوب، يُفرّغ الاسم ولا يُربط معرّف — لا يُحفَظ الربط حتى يُكمّل المستخدم بحساب فرعي. */
    function glStripResolvedRow(tr) {
        if (!tr) {
            return;
        }
        tr.setAttribute('data-account-id', '0');
        var n = tr.querySelector('.gl-inp-name');
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
        var mySeq = ++glPickSeq;
        var url = '/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || '');
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (mySeq !== glPickSeq) {
                    return;
                }
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
                            glStripResolvedRow(tr);
                            return;
                        }
                        glFillRow(tr, data.account);
                    })
                    .catch(function (e) {
                        glStripResolvedRow(tr);
                        alert(e.message || String(e));
                    });
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
        var incomplete = false;
        document.querySelectorAll('tr[data-gl-key]').forEach(function (tr) {
            var codeEl = tr.querySelector('.gl-inp-code');
            var codeTxt = codeEl ? String(codeEl.value || '').trim() : '';
            var id = parseInt(tr.getAttribute('data-account-id'), 10) || 0;
            if (codeTxt !== '' && id <= 0) {
                incomplete = true;
            }
        });
        if (incomplete) {
            alert('يوجد كود مكتوب دون حساب فرعي — لن يُحفظ الربط. إمّا اختر حساباً فرعياً (يظهر الاسم) أو امسح الكود.');
            return;
        }
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
})();
</script>
