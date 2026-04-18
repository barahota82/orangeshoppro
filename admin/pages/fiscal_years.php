<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$years = orange_fiscal_years_list($pdo);
usort($years, static function ($a, $b) {
    return strcmp((string) ($a['start_date'] ?? ''), (string) ($b['start_date'] ?? ''));
});
$maxEndY = (int) date('Y');
foreach ($years as $y) {
    $e = (string) ($y['end_date'] ?? '');
    if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $e, $m)) {
        $maxEndY = max($maxEndY, (int) $m[1]);
    }
}
$fySuggestYear = $maxEndY + 1;
?>
<div class="fy-years-page" dir="rtl">
    <h1 class="fy-years-page__title">السنوات المالية</h1>

    <div class="card fy-years-card fy-print-area">
        <div class="table-wrap fy-years-table-wrap">
            <table class="fy-years-table">
                <thead>
                    <tr>
                        <th class="fy-col-num">مسلسل</th>
                        <th class="fy-col-year">السنة</th>
                        <th class="fy-col-date">بداية السنة</th>
                        <th class="fy-col-date">نهاية السنة</th>
                        <th class="fy-col-closed">مغلقة</th>
                        <th class="fy-col-del" aria-label="حذف"></th>
                    </tr>
                </thead>
                <tbody id="fy_tbody">
                    <?php
                    $serial = 0;
                    foreach ($years as $y):
                        ++$serial;
                        $id = (int) $y['id'];
                        $closed = (int) ($y['is_closed'] ?? 0) === 1;
                        $sd = (string) ($y['start_date'] ?? '');
                        $ed = (string) ($y['end_date'] ?? '');
                        $yr = preg_match('/^(\d{4})-\d{2}-\d{2}$/', $sd, $mm) ? (int) $mm[1] : '';
                        ?>
                    <tr data-fy-row data-id="<?php echo $id; ?>">
                        <td class="fy-col-num"><span class="fy-serial"><?php echo $serial; ?></span></td>
                        <td class="fy-col-year">
                            <input type="number" class="fy-inp-year" min="1900" max="2100" step="1" value="<?php echo $yr !== '' ? $yr : ''; ?>" aria-label="السنة">
                        </td>
                        <td class="fy-col-date">
                            <input type="date" class="fy-inp-start" value="<?php echo htmlspecialchars($sd, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="fy-col-date">
                            <input type="date" class="fy-inp-end" value="<?php echo htmlspecialchars($ed, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="fy-col-closed fy-col-center">
                            <input type="checkbox" class="fy-chk-closed" <?php echo $closed ? ' checked' : ''; ?>>
                        </td>
                        <td class="fy-col-del fy-col-center">
                            <button type="button" class="fy-btn-del btn-secondary" title="حذف">حذف</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($years === []): ?>
            <p class="muted fy-empty-hint" id="fy_empty_hint">لا توجد سنوات — اضغط «إضافة» ثم «حفظ».</p>
        <?php endif; ?>

        <div class="fy-actions">
            <button type="button" class="btn-secondary" id="fy_btn_add">إضافة</button>
            <button type="button" id="fy_btn_save">حفظ</button>
            <button type="button" class="btn-secondary" id="fy_btn_print">طباعة</button>
        </div>
    </div>
</div>

<script>
(function () {
    var tbody = document.getElementById('fy_tbody');
    var suggestYear = <?php echo (int) $fySuggestYear; ?>;

    function pad2(n) { return n < 10 ? '0' + n : String(n); }
    function syncDatesFromYear(tr, y) {
        if (!y || y < 1900 || y > 2100) {
            return;
        }
        var s = tr.querySelector('.fy-inp-start');
        var e = tr.querySelector('.fy-inp-end');
        if (s) {
            s.value = y + '-01-01';
        }
        if (e) {
            e.value = y + '-12-31';
        }
    }
    function syncYearFromStart(tr) {
        var s = tr.querySelector('.fy-inp-start');
        var yIn = tr.querySelector('.fy-inp-year');
        if (!s || !yIn || !s.value || s.value.length < 4) {
            return;
        }
        var y = parseInt(s.value.slice(0, 4), 10);
        if (!isNaN(y)) {
            yIn.value = y;
        }
    }
    function renumberRows() {
        var rows = tbody.querySelectorAll('tr[data-fy-row]');
        for (var i = 0; i < rows.length; i++) {
            var sp = rows[i].querySelector('.fy-serial');
            if (sp) {
                sp.textContent = String(i + 1);
            }
        }
        var hint = document.getElementById('fy_empty_hint');
        if (hint) {
            hint.style.display = rows.length ? 'none' : '';
        }
    }
    function collectRows() {
        var out = [];
        tbody.querySelectorAll('tr[data-fy-row]').forEach(function (tr) {
            var id = parseInt(tr.getAttribute('data-id'), 10) || 0;
            var start = (tr.querySelector('.fy-inp-start') || {}).value || '';
            var end = (tr.querySelector('.fy-inp-end') || {}).value || '';
            var closedEl = tr.querySelector('.fy-chk-closed');
            var isClosed = closedEl && closedEl.checked;
            out.push({
                id: id,
                start_date: start,
                end_date: end,
                is_closed: !!isClosed
            });
        });
        return out;
    }

    tbody.addEventListener('change', function (ev) {
        var t = ev.target;
        var tr = t.closest('tr[data-fy-row]');
        if (!tr) {
            return;
        }
        if (t.classList.contains('fy-inp-year')) {
            var y = parseInt(t.value, 10);
            if (!isNaN(y)) {
                syncDatesFromYear(tr, y);
            }
        }
        if (t.classList.contains('fy-inp-start')) {
            syncYearFromStart(tr);
        }
    });

    tbody.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.fy-btn-del');
        if (!btn) {
            return;
        }
        var tr = btn.closest('tr[data-fy-row]');
        if (!tr) {
            return;
        }
        var id = parseInt(tr.getAttribute('data-id'), 10) || 0;
        function removeLocal() {
            tr.remove();
            renumberRows();
        }
        if (id <= 0) {
            removeLocal();
            return;
        }
        if (!confirm('حذف هذه السنة من الجدول؟')) {
            return;
        }
        postJSON('/admin/api/fiscal_years/save.php', { action: 'delete', id: id })
            .then(function (r) {
                alert(r.message || (r.success ? 'تم' : 'فشل'));
                if (r.success) {
                    removeLocal();
                }
            })
            .catch(function (e) { alert(e.message || String(e)); });
    });

    document.getElementById('fy_btn_add').addEventListener('click', function () {
        var y = suggestYear++;
        var tr = document.createElement('tr');
        tr.setAttribute('data-fy-row');
        tr.setAttribute('data-id', '0');
        tr.innerHTML =
            '<td class="fy-col-num"><span class="fy-serial">0</span></td>' +
            '<td class="fy-col-year"><input type="number" class="fy-inp-year" min="1900" max="2100" step="1" value="' + y + '" aria-label="السنة"></td>' +
            '<td class="fy-col-date"><input type="date" class="fy-inp-start" value="' + y + '-01-01"></td>' +
            '<td class="fy-col-date"><input type="date" class="fy-inp-end" value="' + y + '-12-31"></td>' +
            '<td class="fy-col-closed fy-col-center"><input type="checkbox" class="fy-chk-closed"></td>' +
            '<td class="fy-col-del fy-col-center"><button type="button" class="fy-btn-del btn-secondary" title="حذف">حذف</button></td>';
        tbody.appendChild(tr);
        renumberRows();
    });

    document.getElementById('fy_btn_save').addEventListener('click', function () {
        var rows = collectRows();
        if (rows.length === 0) {
            alert('لا توجد صفوف للحفظ');
            return;
        }
        postJSON('/admin/api/fiscal_years/save.php', { action: 'save_rows', rows: rows })
            .then(function (r) {
                alert(r.message || (r.success ? 'تم' : 'فشل'));
                if (r.success) {
                    location.reload();
                }
            })
            .catch(function (e) { alert(e.message || String(e)); });
    });

    document.getElementById('fy_btn_print').addEventListener('click', function () {
        window.print();
    });

    renumberRows();
})();
</script>
