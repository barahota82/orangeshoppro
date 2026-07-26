<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/report_export.php';
require_once __DIR__ . '/../../includes/admin_time.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$rows = [];
$total = 0;
$hasAuditTable = orange_table_exists($pdo, 'orange_admin_audit_log');
$hasAdmins = orange_table_exists($pdo, 'admins');
$hasCountryCol = $hasAuditTable && orange_table_has_column($pdo, 'orange_admin_audit_log', 'country_id');
$hasGlobalCol = $hasAuditTable && orange_table_has_column($pdo, 'orange_admin_audit_log', 'is_global');
$adminOptions = [];
$ctxCountryId = orange_admin_context_country_id($pdo);
$filterTz = '';
try {
    if ($ctxCountryId > 0) {
        $filterTz = orange_admin_time_timezone_for_country_id($pdo, $ctxCountryId);
    }
} catch (Throwable $e) {
    $filterTz = '';
}

$fromInput = trim((string) ($_GET['from'] ?? ''));
$toInput = trim((string) ($_GET['to'] ?? ''));
$adminFilterId = (int) ($_GET['admin_id'] ?? 0);

$fromYmd = orange_parse_admin_date_to_ymd($fromInput);
$toYmd = orange_parse_admin_date_to_ymd($toInput);
$hasFilters = $fromInput !== '' || $toInput !== '' || $adminFilterId > 0;

$where = [];
$whereParams = [];
if ($hasCountryCol) {
    $where[] = 'l.country_id = ?';
    $whereParams[] = $ctxCountryId;
    if ($hasGlobalCol) {
        $where[] = 'l.is_global = 0';
    }
}
if (($fromYmd !== '' || $toYmd !== '') && $filterTz !== '') {
    $range = orange_admin_time_filter_range_mysql_utc($fromYmd, $toYmd, $filterTz);
    if ($range !== null) {
        $startUnix = orange_admin_time_parse_utc_instant(
            orange_admin_time_parse_mysql_utc_datetime($range['start_utc_mysql'])->format('c')
        )->getTimestamp();
        $endUnix = orange_admin_time_parse_utc_instant(
            orange_admin_time_parse_mysql_utc_datetime($range['end_exclusive_utc_mysql'])->format('c')
        )->getTimestamp();
        $where[] = 'UNIX_TIMESTAMP(l.created_at) >= ?';
        $whereParams[] = $startUnix;
        $where[] = 'UNIX_TIMESTAMP(l.created_at) < ?';
        $whereParams[] = $endUnix;
    }
}
if ($adminFilterId > 0) {
    $where[] = 'l.admin_id = ?';
    $whereParams[] = $adminFilterId;
}
$whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

if ($hasAdmins) {
    $adminOptions = $pdo->query(
        'SELECT id, username, display_name
         FROM admins
         ORDER BY username ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$displayForRow = static function (PDO $pdo, array $r, int $ctxCountryId): string {
    $unix = orange_admin_time_unix_or_null($r['created_at_unix'] ?? null);
    $cid = (int) ($r['country_id'] ?? 0);
    if ($cid <= 0) {
        $cid = $ctxCountryId;
    }
    if ($unix === null || $cid <= 0) {
        return '—';
    }

    return orange_admin_time_display_unix_for_record($pdo, $unix, $cid);
};

if ($hasAuditTable && $ctxCountryId > 0) {
    $countSt = $pdo->prepare('SELECT COUNT(*) FROM orange_admin_audit_log l' . $whereSql);
    $countSt->execute($whereParams);
    $total = (int) $countSt->fetchColumn();

    if ($hasAdmins) {
        $sql = 'SELECT l.id, l.created_at, UNIX_TIMESTAMP(l.created_at) AS created_at_unix,
                l.admin_id, l.action, l.message, l.entity_table, l.entity_id,
                ' . ($hasCountryCol ? 'l.country_id,' : 'NULL AS country_id,') . '
                a.username AS admin_username
                FROM orange_admin_audit_log l
                LEFT JOIN admins a ON a.id = l.admin_id
                ' . $whereSql . '
                ORDER BY l.id DESC
                LIMIT 500';
    } else {
        $sql = 'SELECT l.id, l.created_at, UNIX_TIMESTAMP(l.created_at) AS created_at_unix,
                l.admin_id, l.action, l.message, l.entity_table, l.entity_id,
                ' . ($hasCountryCol ? 'l.country_id,' : 'NULL AS country_id,') . '
                NULL AS admin_username
                FROM orange_admin_audit_log l
                ' . $whereSql . '
                ORDER BY l.id DESC
                LIMIT 500';
    }
    $rowsSt = $pdo->prepare($sql);
    $rowsSt->execute($whereParams);
    $rows = $rowsSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if (isset($_GET['export']) && (string) $_GET['export'] === 'xls' && $hasAuditTable && $ctxCountryId > 0) {
    $exportSql = $hasAdmins
        ? 'SELECT l.id, l.created_at, UNIX_TIMESTAMP(l.created_at) AS created_at_unix,
                l.admin_id, l.action, l.message, l.entity_table, l.entity_id,
                ' . ($hasCountryCol ? 'l.country_id,' : 'NULL AS country_id,') . '
                a.username AS admin_username
                FROM orange_admin_audit_log l
                LEFT JOIN admins a ON a.id = l.admin_id
                ' . $whereSql . '
                ORDER BY l.id DESC
                LIMIT 8000'
        : 'SELECT l.id, l.created_at, UNIX_TIMESTAMP(l.created_at) AS created_at_unix,
                l.admin_id, l.action, l.message, l.entity_table, l.entity_id,
                ' . ($hasCountryCol ? 'l.country_id,' : 'NULL AS country_id,') . '
                NULL AS admin_username
                FROM orange_admin_audit_log l
                ' . $whereSql . '
                ORDER BY l.id DESC
                LIMIT 8000';
    $exportSt = $pdo->prepare($exportSql);
    $exportSt->execute($whereParams);
    $exportRows = $exportSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $subtitleParts = [];
    if ($fromYmd !== '') {
        $subtitleParts[] = 'من ' . orange_format_date_dmY($fromYmd);
    }
    if ($toYmd !== '') {
        $subtitleParts[] = 'إلى ' . orange_format_date_dmY($toYmd);
    }
    if ($adminFilterId > 0) {
        foreach ($adminOptions as $aOpt) {
            if ((int) ($aOpt['id'] ?? 0) === $adminFilterId) {
                $u = trim((string) ($aOpt['username'] ?? ''));
                $subtitleParts[] = 'المستخدم: ' . ($u !== '' ? $u : ('#' . $adminFilterId));
                break;
            }
        }
    }
    $subtitle = $subtitleParts !== [] ? implode(' — ', $subtitleParts) . ' — عدد الصفوف: ' . count($exportRows) : 'عدد الصفوف: ' . count($exportRows);

    $xlsRows = [];
    foreach ($exportRows as $r) {
        $u = trim((string) ($r['admin_username'] ?? ''));
        if ($u === '') {
            $aid = isset($r['admin_id']) ? (int) $r['admin_id'] : 0;
            $u = $aid > 0 ? '#' . $aid : '—';
        }
        $et = (string) ($r['entity_table'] ?? '');
        $ei = (string) ($r['entity_id'] ?? '');
        $entity = $et !== '' ? $et . ($ei !== '' ? ' #' . $ei : '') : '—';
        $xlsRows[] = [
            (int) ($r['id'] ?? 0),
            $displayForRow($pdo, $r, $ctxCountryId),
            $u,
            (string) ($r['action'] ?? ''),
            (string) ($r['message'] ?? ''),
            $entity,
        ];
    }

    orange_report_xls_output(
        'سجل النشاط',
        'سجل النشاط',
        orange_company_settings_name_ar($pdo),
        $subtitle,
        ['#', 'الوقت', 'المستخدم', 'الإجراء', 'الوصف', 'الكيان'],
        $xlsRows,
        [0]
    );
    exit;
}
?>
<div class="page-title">
    <h1>سجل النشاط</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<div class="card">
    <h3 class="card-title">فلترة السجل</h3>
    <form method="get" class="logs-filter-form">
        <input type="hidden" name="page" value="logs">
        <div>
            <label for="logs_from">من تاريخ</label>
            <input type="text" id="logs_from" name="from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" value="<?php echo htmlspecialchars($fromInput, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
            <label for="logs_to">إلى تاريخ</label>
            <input type="text" id="logs_to" name="to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off" value="<?php echo htmlspecialchars($toInput, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
            <label for="logs_admin_id">المستخدم</label>
            <select id="logs_admin_id" name="admin_id">
                <option value="0">كل المستخدمين</option>
                <?php foreach ($adminOptions as $aOpt): ?>
                <?php
                $aid = (int) ($aOpt['id'] ?? 0);
                if ($aid <= 0) {
                    continue;
                }
                $u = trim((string) ($aOpt['username'] ?? ''));
                $dn = trim((string) ($aOpt['display_name'] ?? ''));
                $label = $u !== '' ? $u : ('#' . $aid);
                if ($dn !== '') {
                    $label .= ' — ' . $dn;
                }
                ?>
                <option value="<?php echo $aid; ?>"<?php echo $aid === $adminFilterId ? ' selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="logs-filter-actions">
            <button type="submit">بحث</button>
            <?php
            $logsXlsQ = $_GET;
            $logsXlsQ['page'] = 'logs';
            $logsXlsQ['export'] = 'xls';
            $logsXlsHref = storefront_public_path('/admin/index.php') . '?' . http_build_query($logsXlsQ);
            ?>
            <a class="btn-secondary" data-server-export href="<?php echo htmlspecialchars($logsXlsHref, ENT_QUOTES, 'UTF-8'); ?>">Excel</a>
            <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=logs'), ENT_QUOTES, 'UTF-8'); ?>">إعادة ضبط</a>
        </div>
    </form>
</div>

<div class="card">
    <h3 class="card-title">آخر السجلات (حتى 500)</h3>
    <?php if ($ctxCountryId <= 0): ?>
        <p class="muted">اختر دولة من سياق الإدمن لعرض سجل النشاط.</p>
    <?php elseif ($rows === []): ?>
        <?php if (!$hasAuditTable): ?>
            <p class="muted">جدول سجل النشاط غير جاهز بعد.</p>
        <?php elseif ($hasFilters): ?>
            <p class="muted">لا توجد نتائج مطابقة للفلاتر المحددة.</p>
            <p class="muted">إجمالي النتائج: 0</p>
        <?php else: ?>
            <p class="muted">لا توجد سجلات بعد. بعد أول عملية تُسجَّل (مثلاً حفظ حساب أو قيد) ستظهر هنا.</p>
            <p class="muted">إجمالي السجلات في القاعدة: 0</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted" style="margin-bottom:12px;">إجمالي النتائج: <?php echo (int) $total; ?> — المعروض: <?php echo count($rows); ?></p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الوقت</th>
                        <th>المستخدم</th>
                        <th>الإجراء</th>
                        <th>الوصف</th>
                        <th>الكيان</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo (int) $r['id']; ?></td>
                            <td dir="ltr" style="white-space:nowrap;"><?php echo htmlspecialchars($displayForRow($pdo, $r, $ctxCountryId), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php
                                $u = trim((string) ($r['admin_username'] ?? ''));
                                if ($u === '') {
                                    $aid = isset($r['admin_id']) ? (int) $r['admin_id'] : 0;
                                    echo $aid > 0 ? '#' . $aid : '—';
                                } else {
                                    echo htmlspecialchars($u, ENT_QUOTES, 'UTF-8');
                                }
                                ?>
                            </td>
                            <td><code><?php echo htmlspecialchars((string) ($r['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code></td>
                            <td><?php echo htmlspecialchars((string) ($r['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php
                                $et = (string) ($r['entity_table'] ?? '');
                                $ei = (string) ($r['entity_id'] ?? '');
                                $cell = $et !== '' ? $et . ($ei !== '' ? ' #' . $ei : '') : '—';
                                echo htmlspecialchars($cell, ENT_QUOTES, 'UTF-8');
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
