<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$rows = [];
$total = 0;
if (orange_table_exists($pdo, 'orange_admin_audit_log')) {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM orange_admin_audit_log')->fetchColumn();
    $hasAdmins = orange_table_exists($pdo, 'admins');
    if ($hasAdmins) {
        $sql = 'SELECT l.id, l.created_at, l.admin_id, l.action, l.message, l.entity_table, l.entity_id,
                a.username AS admin_username
                FROM orange_admin_audit_log l
                LEFT JOIN admins a ON a.id = l.admin_id
                ORDER BY l.id DESC
                LIMIT 500';
    } else {
        $sql = 'SELECT id, created_at, admin_id, action, message, entity_table, entity_id,
                NULL AS admin_username
                FROM orange_admin_audit_log
                ORDER BY id DESC
                LIMIT 500';
    }
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="page-title">
    <h1>سجل النشاط</h1>
    <p class="page-subtitle muted">
        آخر العمليات التي سجّلها النظام من لوحة الإدارة (حفظ، حذف، قيود، …). يُحدَّث تلقائياً عند استدعاء <code>audit_log</code> من الـ API.
    </p>
</div>

<div class="card">
    <h3 class="card-title">آخر السجلات (حتى 500)</h3>
    <?php if ($rows === []): ?>
        <p class="muted">لا توجد سجلات بعد. بعد أول عملية تُسجَّل (مثلاً حفظ حساب أو قيد) ستظهر هنا.</p>
        <?php if ($total === 0 && orange_table_exists($pdo, 'orange_admin_audit_log')): ?>
            <p class="muted">إجمالي السجلات في القاعدة: 0</p>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted" style="margin-bottom:12px;">إجمالي السجلات: <?php echo (int) $total; ?> — المعروض: <?php echo count($rows); ?></p>
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
                            <td dir="ltr" style="white-space:nowrap;"><?php echo htmlspecialchars((string) ($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
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
