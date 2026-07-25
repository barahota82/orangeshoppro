<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/order_intake_queue.php';

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, string>
 */
function orange_intake_channel_map(PDO $pdo, array $rows): array
{
    $ids = [];
    foreach ($rows as $r) {
        $j = json_decode((string) ($r['payload_json'] ?? ''), true);
        if (is_array($j) && isset($j['channel_id'])) {
            $cid = (int) $j['channel_id'];
            if ($cid > 0) {
                $ids[$cid] = true;
            }
        }
    }
    $idList = array_keys($ids);
    if ($idList === [] || !orange_table_exists($pdo, 'channels')) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    $st = $pdo->prepare("SELECT id, name FROM channels WHERE id IN ($placeholders)");
    $st->execute($idList);
    $map = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $map[(int) $row['id']] = trim((string) ($row['name'] ?? ''));
    }

    return $map;
}

/**
 * @param array<string, mixed> $row
 * @param array<int, string> $chMap
 */
function orange_intake_payload_summary(array $row, array $chMap = []): string
{
    $raw = (string) ($row['payload_json'] ?? '');
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return '—';
    }
    $name = trim((string) ($j['name'] ?? ''));
    $phone = trim((string) ($j['phone'] ?? ''));
    $cid = isset($j['channel_id']) ? (int) $j['channel_id'] : 0;
    $chLabel = '';
    if ($cid > 0) {
        $chLabel = $chMap[$cid] ?? ('قناة #' . $cid);
    }
    $parts = array_filter([$name, $phone !== '' ? $phone : null, $chLabel !== '' ? $chLabel : null]);

    return $parts !== [] ? implode(' · ', $parts) : '—';
}

/**
 * @param array<string, mixed> $row
 */
function orange_intake_payload_pretty(array $row): string
{
    $raw = (string) ($row['payload_json'] ?? '');
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return $raw === '' ? '' : $raw;
    }

    return (string) json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

$pdo = db();
orange_catalog_ensure_schema($pdo);

$mayEdit = orange_admin_may($admin, $pdo, 'sales', 'edit');
$mayDelete = orange_admin_may($admin, $pdo, 'sales', 'delete');

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
if (!in_array($statusFilter, ['all', 'pending', 'failed', 'completed'], true)) {
    $statusFilter = 'all';
}

$counts = ['pending' => 0, 'failed' => 0, 'completed' => 0, 'total' => 0];
$rows = [];

if (orange_table_exists($pdo, 'order_intake_queue')) {
    $intakeScope = orange_order_intake_sql_country_scope($pdo, 'oiq');
    try {
        if ($intakeScope !== null) {
            $cst = $pdo->prepare(
                'SELECT oiq.status, COUNT(*) AS c FROM order_intake_queue oiq'
                . $intakeScope['join']
                . ' WHERE 1=1'
                . $intakeScope['where']
                . ' GROUP BY oiq.status'
            );
            $cst->execute($intakeScope['params']);
        } else {
            $cst = $pdo->query(
                'SELECT status, COUNT(*) AS c FROM order_intake_queue GROUP BY status'
            );
        }
        foreach ($cst->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $k = (string) ($c['status'] ?? '');
            $counts[$k] = (int) ($c['c'] ?? 0);
            $counts['total'] += (int) ($c['c'] ?? 0);
        }
    } catch (Throwable $e) {
        $counts = ['pending' => 0, 'failed' => 0, 'completed' => 0, 'total' => 0];
    }

    $sql = 'SELECT oiq.id, oiq.public_token, oiq.status, oiq.order_id, oiq.order_number, oiq.error_message, oiq.attempts,
                   oiq.created_at, oiq.updated_at,
                   UNIX_TIMESTAMP(oiq.created_at) AS created_at_unix,
                   UNIX_TIMESTAMP(oiq.updated_at) AS updated_at_unix,
                   oiq.payload_json
            FROM order_intake_queue oiq';
    $params = [];
    if ($intakeScope !== null) {
        $sql .= $intakeScope['join'] . ' WHERE 1=1' . $intakeScope['where'];
        $params = $intakeScope['params'];
    } else {
        $sql .= ' WHERE 1=1';
    }
    if ($statusFilter !== 'all') {
        $sql .= ' AND oiq.status = ?';
        $params[] = $statusFilter;
    }
    $sql .= ' ORDER BY oiq.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$chMap = $rows !== [] ? orange_intake_channel_map($pdo, $rows) : [];

$statusLabel = [
    'pending' => 'قيد الانتظار',
    'failed' => 'فاشل',
    'completed' => 'مكتمل',
];
?>
<div class="page-title">
    <h1>طابور طلبات الموقع</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div class="party-registry-stats" style="margin-bottom:16px;">
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">الإجمالي</span>
        <span class="party-registry-stat__val"><?php echo (int) $counts['total']; ?></span>
    </div>
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">معلّقة</span>
        <span class="party-registry-stat__val"><?php echo (int) ($counts['pending'] ?? 0); ?></span>
    </div>
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">فاشلة</span>
        <span class="party-registry-stat__val"><?php echo (int) ($counts['failed'] ?? 0); ?></span>
    </div>
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">مكتملة</span>
        <span class="party-registry-stat__val"><?php echo (int) ($counts['completed'] ?? 0); ?></span>
    </div>
</div>

<?php if ($mayEdit): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 class="card-title">معالجة يدوية</h3>
    <p class="card-hint" style="margin-top:0;">
        نفّذ خطوة أو أكثر من طابور الانتظار (FIFO). مفيد إن توقفت المعالجة التلقائية، أو بعد إصلاح مخزون/منتج، أو للاختبار.
    </p>
    <div class="actions" style="flex-wrap:wrap;gap:8px;">
        <button type="button" class="btn-secondary" id="intake_process_1" data-intake-busy="0">معالجة مهمة واحدة</button>
        <button type="button" id="intake_process_5" data-intake-busy="0">معالجة حتى 5 مهام</button>
        <?php if ((int) ($counts['failed'] ?? 0) > 0): ?>
            <button type="button" class="btn-secondary" id="intake_retry_bulk_btn" data-intake-busy="0">إعادة كل الفاشل (حتى 25)</button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($mayDelete): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 class="card-title">تنظيف الصفوف</h3>
    <p class="card-hint" style="margin-top:0;">
        حذف صفوف <strong>مكتملة</strong> أقدم من عدد الأيام المحدّد، وصفوف <strong>فاشلة</strong> أقدم من عدد الأيام المحدّد.
        لا يُحذف شيء من الطلبات الفعلية في <code dir="ltr">orders</code>.
    </p>
    <div class="form-grid" style="max-width:520px;">
        <div>
            <label for="intake_clean_completed">حذف «مكتمل» أقدم من (يوم)</label>
            <input type="number" id="intake_clean_completed" value="30" min="1" max="3650">
        </div>
        <div>
            <label for="intake_clean_failed">حذف «فاشل» أقدم من (يوم)</label>
            <input type="number" id="intake_clean_failed" value="90" min="1" max="3650">
        </div>
    </div>
    <div class="actions" style="margin-top:12px;">
        <button type="button" class="btn-secondary" id="intake_cleanup_btn" data-intake-busy="0">تنظيف الآن</button>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">السجل</h3>
    <div class="admin-intake-toolbar">
        <div class="admin-intake-toolbar__search">
            <label for="intake_table_search" class="muted" style="display:block;font-size:0.9em;margin-bottom:4px;">بحث في المعروض (رقم، هاتف، رمز، خطأ…)</label>
            <input type="search" id="intake_table_search" autocomplete="off" placeholder="ابحث في الصفوف الظاهرة…" dir="rtl">
        </div>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
            <span class="muted">تصفية السيرفر:</span>
            <?php
            $base = storefront_public_path('/admin/index.php?page=order_intake_queue');
            $filters = [
                'all' => 'الكل',
                'pending' => 'معلّقة',
                'failed' => 'فاشلة',
                'completed' => 'مكتملة',
            ];
            foreach ($filters as $k => $lab) {
                $href = $k === 'all' ? $base : $base . '&status=' . rawurlencode($k);
                $cls = $statusFilter === $k ? 'btn' : 'btn-secondary';
                echo '<a class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') . '</a>';
            }
            ?>
            <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/api/order_intake/export-excel.php?status=' . rawurlencode($statusFilter)), ENT_QUOTES, 'UTF-8'); ?>">Excel</a>
        </div>
        <a class="btn-secondary" style="margin-inline-start:auto;" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=orders'), ENT_QUOTES, 'UTF-8'); ?>">الطلبات</a>
    </div>

    <?php if (!orange_table_exists($pdo, 'order_intake_queue')): ?>
        <p class="muted">جدول الطابور غير موجود — شغّل ترقية المخطط (أو افتح الموقع مرة) لإنشاء <code dir="ltr">order_intake_queue</code>.</p>
    <?php elseif ($rows === []): ?>
        <p class="muted">لا توجد صفوف لهذا التصفية.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الحالة</th>
                        <th>ملخّص الطلب</th>
                        <th>رقم الطلب</th>
                        <th>محاولات</th>
                        <th>أنشئ</th>
                        <th>آخر تحديث</th>
                        <th>خطأ / تفاصيل</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $rid = (int) $r['id'];
                        $st = (string) ($r['status'] ?? '');
                        $stAr = $statusLabel[$st] ?? $st;
                        ?>
                        <tr>
                            <td><?php echo $rid; ?></td>
                            <td><?php echo htmlspecialchars($stAr, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="auto"><?php echo htmlspecialchars(orange_intake_payload_summary($r), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td dir="ltr">
                                <?php
                                $on = trim((string) ($r['order_number'] ?? ''));
                                $oid = (int) ($r['order_id'] ?? 0);
                                if ($oid > 0 && $st === 'completed') {
                                    $invHref = storefront_public_path('/admin/index.php?page=invoice&order_id=' . $oid);
                                    $disp = $on !== '' ? $on : ('#' . $oid);
                                    echo '<a class="btn-secondary" style="display:inline-block;padding:2px 8px;font-size:0.9em;" href="' . htmlspecialchars($invHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($disp, ENT_QUOTES, 'UTF-8') . '</a>';
                                } elseif ($on !== '') {
                                    echo htmlspecialchars($on, ENT_QUOTES, 'UTF-8');
                                } elseif ($oid > 0) {
                                    echo '#' . $oid;
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td><?php echo (int) ($r['attempts'] ?? 0); ?></td>
                            <td dir="ltr" style="white-space:nowrap;"><?php
                                $intakeCid = orange_order_intake_row_country_id($pdo, $r);
                                $cUnix = orange_admin_time_unix_or_null($r['created_at_unix'] ?? null);
                                echo htmlspecialchars(
                                    orange_admin_time_display_unix_for_record($pdo, $cUnix, $intakeCid),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            ?></td>
                            <td dir="ltr" style="white-space:nowrap;"><?php
                                $uUnix = orange_admin_time_unix_or_null($r['updated_at_unix'] ?? null);
                                echo htmlspecialchars(
                                    orange_admin_time_display_unix_for_record($pdo, $uUnix, $intakeCid),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            ?></td>
                            <td style="max-width:220px;">
                                <?php if ($st === 'failed' && trim((string) ($r['error_message'] ?? '')) !== ''): ?>
                                    <?php
                                    $errFull = (string) $r['error_message'];
                                    $snip = function_exists('mb_substr')
                                        ? mb_substr($errFull, 0, 80, 'UTF-8')
                                        : substr($errFull, 0, 80);
                                    $long = function_exists('mb_strlen')
                                        ? mb_strlen($errFull, 'UTF-8') > 80
                                        : strlen($errFull) > 80;
                                    ?>
                                    <span class="muted" title="<?php echo htmlspecialchars($errFull, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($snip, ENT_QUOTES, 'UTF-8'); ?>
                                        <?php echo $long ? '…' : ''; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                                <details style="margin-top:6px;">
                                    <summary style="cursor:pointer;font-size:0.9em;">حمولة JSON</summary>
                                    <pre dir="ltr" style="max-height:200px;overflow:auto;font-size:11px;margin:8px 0 0;text-align:left;"><?php
                                        echo htmlspecialchars(orange_intake_payload_pretty($r), ENT_QUOTES, 'UTF-8');
                                    ?></pre>
                                </details>
                            </td>
                            <td>
                                <?php if ($st === 'failed' && $mayEdit): ?>
                                    <div class="intake-action-stack">
                                        <button type="button" class="btn-secondary intake-retry-btn" data-id="<?php echo $rid; ?>" data-intake-busy="0">إعادة للطابور</button>
                                        <?php if ($mayDelete): ?>
                                            <button type="button" class="btn-danger intake-delete-btn" data-id="<?php echo $rid; ?>" data-intake-busy="0">حذف الصف</button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    function setBusy(btn, busy, labelBusy) {
        if (!btn) return;
        if (busy) {
            if (!btn.dataset.intakeLabel) {
                btn.dataset.intakeLabel = btn.textContent || '';
            }
            btn.disabled = true;
            btn.textContent = labelBusy || 'جاري…';
        } else {
            btn.disabled = false;
            if (btn.dataset.intakeLabel) {
                btn.textContent = btn.dataset.intakeLabel;
            }
        }
    }

    function copyToken(t) {
        if (!t) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(t).then(function () {
                orangeAdminFlash('تم نسخ رمز الاستعلام', 'ok');
            }).catch(function () {
                orangeAdminFlash('تعذّر النسخ من المتصفح', 'err');
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = t;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                orangeAdminFlash('تم النسخ', 'ok');
            } catch (e) {
                orangeAdminFlash('تعذّر النسخ', 'err');
            }
            document.body.removeChild(ta);
        }
    }

    function intakeProcess(count, btn) {
        setBusy(btn, true, 'جاري المعالجة…');
        postJSON('/admin/api/order_intake/process-next.php', { count: count })
            .then(function (r) {
                setBusy(btn, false);
                if (r.success) {
                    orangeAdminFlash(r.message || ('تمت معالجة ' + (r.processed || 0) + ' مهمة'), 'ok');
                    location.reload();
                } else {
                    orangeAdminFlash(r.message || 'فشل المعالجة', 'err');
                }
            })
            .catch(function (e) {
                setBusy(btn, false);
                orangeAdminFlash(e.message || String(e), 'err');
            });
    }

    var b1 = document.getElementById('intake_process_1');
    var b5 = document.getElementById('intake_process_5');
    if (b1) {
        b1.addEventListener('click', function () {
            intakeProcess(1, b1);
        });
    }
    if (b5) {
        b5.addEventListener('click', function () {
            intakeProcess(5, b5);
        });
    }

    var bulkRetry = document.getElementById('intake_retry_bulk_btn');
    if (bulkRetry) {
        bulkRetry.addEventListener('click', function () {
            if (!confirm('إعادة أقدم 25 صفاً فاشلاً (أو أقل إن لم يتوفر) إلى قيد الانتظار؟ ستُعالَج لاحقاً بالتسلسل.')) {
                return;
            }
            setBusy(bulkRetry, true, 'جاري…');
            postJSON('/admin/api/order_intake/retry-bulk-failed.php', { max: 25 })
                .then(function (r) {
                    setBusy(bulkRetry, false);
                    if (r.success) {
                        orangeAdminFlash(r.message || 'تم', 'ok');
                        location.reload();
                    } else {
                        orangeAdminFlash(r.message || 'فشل', 'err');
                    }
                })
                .catch(function (e) {
                    setBusy(bulkRetry, false);
                    orangeAdminFlash(e.message || String(e), 'err');
                });
        });
    }

    var cleanBtn = document.getElementById('intake_cleanup_btn');
    if (cleanBtn) {
        cleanBtn.addEventListener('click', function () {
            if (!confirm('تأكيد حذف صفوف الطابور القديمة؟ لا يُحذف شيء من جدول الطلبات الفعلية.')) {
                return;
            }
            setBusy(cleanBtn, true, 'جاري التنظيف…');
            var cd = parseInt(document.getElementById('intake_clean_completed').value, 10) || 30;
            var fd = parseInt(document.getElementById('intake_clean_failed').value, 10) || 90;
            postJSON('/admin/api/order_intake/cleanup.php', {
                completed_older_than_days: cd,
                failed_older_than_days: fd
            })
                .then(function (r) {
                    setBusy(cleanBtn, false);
                    if (r.success) {
                        orangeAdminFlash(
                            r.message ||
                                ('حذف مكتمل: ' + (r.deleted_completed || 0) + '، فاشل: ' + (r.deleted_failed || 0)),
                            'ok'
                        );
                        location.reload();
                    } else {
                        orangeAdminFlash(r.message || 'فشل التنظيف', 'err');
                    }
                })
                .catch(function (e) {
                    setBusy(cleanBtn, false);
                    orangeAdminFlash(e.message || String(e), 'err');
                });
        });
    }

    document.querySelectorAll('.intake-copy-token').forEach(function (btn) {
        btn.addEventListener('click', function () {
            copyToken(btn.getAttribute('data-token') || '');
        });
    });

    var searchEl = document.getElementById('intake_table_search');
    var tbody = document.getElementById('intake_tbody');
    function intakeFilterRows() {
        if (!searchEl || !tbody) return;
        var q = (searchEl.value || '').trim().toLowerCase();
        tbody.querySelectorAll('tr[data-intake-hay]').forEach(function (tr) {
            var hay = (tr.getAttribute('data-intake-hay') || '').toLowerCase();
            tr.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
        });
    }
    if (searchEl) {
        searchEl.addEventListener('input', intakeFilterRows);
    }

    document.querySelectorAll('.intake-retry-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
            if (!id) return;
            if (!confirm('إعادة هذا الصف إلى قيد الانتظار؟ سيُعاد إنشاء الطلب عند وصول دوره في الطابور.')) {
                return;
            }
            setBusy(btn, true, 'جاري…');
            postJSON('/admin/api/order_intake/retry.php', { id: id })
                .then(function (r) {
                    setBusy(btn, false);
                    if (r.success) {
                        orangeAdminFlash(r.message || 'تمت الإعادة', 'ok');
                        location.reload();
                    } else {
                        orangeAdminFlash(r.message || 'فشل', 'err');
                    }
                })
                .catch(function (e) {
                    setBusy(btn, false);
                    orangeAdminFlash(e.message || String(e), 'err');
                });
        });
    });

    document.querySelectorAll('.intake-delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
            if (!id) return;
            if (!confirm('حذف هذا الصف من الطابور نهائياً؟ (لا يحذف طلباً في جدول الطلبات.)')) {
                return;
            }
            setBusy(btn, true, 'جاري…');
            postJSON('/admin/api/order_intake/delete.php', { id: id })
                .then(function (r) {
                    setBusy(btn, false);
                    if (r.success) {
                        orangeAdminFlash(r.message || 'تم الحذف', 'ok');
                        location.reload();
                    } else {
                        orangeAdminFlash(r.message || 'فشل الحذف', 'err');
                    }
                })
                .catch(function (e) {
                    setBusy(btn, false);
                    orangeAdminFlash(e.message || String(e), 'err');
                });
        });
    });
})();
</script>
