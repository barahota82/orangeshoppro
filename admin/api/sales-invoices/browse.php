<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/sales_invoice_company.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    $countryId = orange_admin_context_country_id($pdo);
    $countryFilter = orange_sales_invoice_company_country_filter($pdo, $countryId, 'o');
    $scopeSql = orange_sales_invoice_company_scope_sql($pdo, 'o');
    $countrySql = $countryFilter !== null ? $countryFilter['sql'] : '';
    $countryParams = $countryFilter !== null ? $countryFilter['params'] : [];

    if ($action === 'nav') {
        $where = trim((string) ($data['where'] ?? ''));
        $currentId = (int) ($data['current_id'] ?? 0);
        if (!in_array($where, ['first', 'prev', 'next', 'last'], true)) {
            json_response(['success' => false, 'message' => 'اتجاه تنقل غير صالح'], 422);
        }

        $baseFrom = ' FROM orders o WHERE 1=1' . $countrySql . $scopeSql;
        $countSt = $pdo->prepare('SELECT COUNT(*)' . $baseFrom);
        $countSt->execute($countryParams);
        if ((int) $countSt->fetchColumn() === 0) {
            json_response(['success' => false, 'message' => 'لا توجد فواتير مبيعات شركة بعد']);
        }

        $targetId = 0;
        if ($where === 'first') {
            $st = $pdo->prepare('SELECT o.id' . $baseFrom . ' ORDER BY o.id ASC LIMIT 1');
            $st->execute($countryParams);
            $targetId = (int) $st->fetchColumn();
        } elseif ($where === 'last') {
            $st = $pdo->prepare('SELECT o.id' . $baseFrom . ' ORDER BY o.id DESC LIMIT 1');
            $st->execute($countryParams);
            $targetId = (int) $st->fetchColumn();
        } elseif ($where === 'prev') {
            if ($currentId <= 0) {
                $st = $pdo->prepare('SELECT o.id' . $baseFrom . ' ORDER BY o.id DESC LIMIT 1');
                $st->execute($countryParams);
                $targetId = (int) $st->fetchColumn();
            } else {
                $params = array_merge($countryParams, [$currentId]);
                $st = $pdo->prepare(
                    'SELECT o.id' . $baseFrom . ' AND o.id < ? ORDER BY o.id DESC LIMIT 1'
                );
                $st->execute($params);
                $targetId = (int) ($st->fetchColumn() ?: 0);
                if ($targetId <= 0) {
                    $st = $pdo->prepare('SELECT o.id' . $baseFrom . ' ORDER BY o.id ASC LIMIT 1');
                    $st->execute($countryParams);
                    $targetId = (int) $st->fetchColumn();
                }
            }
        } elseif ($where === 'next') {
            if ($currentId <= 0) {
                $st = $pdo->prepare('SELECT o.id' . $baseFrom . ' ORDER BY o.id ASC LIMIT 1');
                $st->execute($countryParams);
                $targetId = (int) $st->fetchColumn();
            } else {
                $params = array_merge($countryParams, [$currentId]);
                $st = $pdo->prepare(
                    'SELECT o.id' . $baseFrom . ' AND o.id > ? ORDER BY o.id ASC LIMIT 1'
                );
                $st->execute($params);
                $targetId = (int) ($st->fetchColumn() ?: 0);
                if ($targetId <= 0) {
                    $st = $pdo->prepare('SELECT o.id' . $baseFrom . ' ORDER BY o.id DESC LIMIT 1');
                    $st->execute($countryParams);
                    $targetId = (int) $st->fetchColumn();
                }
            }
        }

        if ($targetId <= 0) {
            json_response(['success' => false, 'message' => 'لا توجد فاتورة في هذا الاتجاه']);
        }

        json_response(['success' => true, 'id' => $targetId]);
    }

    if ($action === 'search') {
        $idFrom = (int) ($data['id_from'] ?? 0);
        $idTo = (int) ($data['id_to'] ?? 0);
        $dateFrom = trim((string) ($data['date_from'] ?? ''));
        $dateTo = trim((string) ($data['date_to'] ?? ''));
        $ref = trim((string) ($data['reference'] ?? ''));
        $customerName = trim((string) ($data['customer_name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $channelFilter = array_key_exists('channel_id', $data) ? (int) $data['channel_id'] : null;
        $notes = trim((string) ($data['notes'] ?? ''));

        $hasCreatedAt = orange_table_has_column($pdo, 'orders', 'created_at');

        $sql = 'SELECT o.id, o.customer_name, o.phone, o.total, o.notes, o.channel_id, ch.name AS channel_name';
        if ($hasCreatedAt) {
            $sql .= ', o.created_at';
        }
        if (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
            $sql .= ', o.invoice_number';
        }
        if (orange_table_has_column($pdo, 'orders', 'order_number')) {
            $sql .= ', o.order_number';
        }
        $sql .= ' FROM orders o
            LEFT JOIN channels ch ON ch.id = o.channel_id
            WHERE 1=1' . $countrySql . $scopeSql;
        $params = $countryParams;

        if ($idFrom > 0) {
            $sql .= ' AND o.id >= ?';
            $params[] = $idFrom;
        }
        if ($idTo > 0) {
            $sql .= ' AND o.id <= ?';
            $params[] = $idTo;
        }
        if ($hasCreatedAt && $dateFrom !== '') {
            $sql .= ' AND DATE(o.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($hasCreatedAt && $dateTo !== '') {
            $sql .= ' AND DATE(o.created_at) <= ?';
            $params[] = $dateTo;
        }
        if ($ref !== '') {
            if (preg_match('/^INV-C-(\d+)$/i', $ref, $m)) {
                $sql .= ' AND o.id = ?';
                $params[] = (int) $m[1];
            } elseif (preg_match('/^(\d+)$/', $ref, $m)) {
                $sql .= ' AND o.id = ?';
                $params[] = (int) $m[1];
            } elseif (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
                $sql .= ' AND o.invoice_number LIKE ?';
                $params[] = '%' . $ref . '%';
            } else {
                $sql .= ' AND CONCAT(\'INV-C-\', o.id) LIKE ?';
                $params[] = '%' . $ref . '%';
            }
        }
        if ($customerName !== '') {
            $sql .= ' AND o.customer_name LIKE ?';
            $params[] = '%' . $customerName . '%';
        }
        if ($phone !== '') {
            $sql .= ' AND o.phone LIKE ?';
            $params[] = '%' . $phone . '%';
        }
        if ($channelFilter !== null && $channelFilter === 0) {
            $sql .= ' AND (o.channel_id IS NULL OR o.channel_id = 0)';
        } elseif ($channelFilter !== null && $channelFilter > 0) {
            $sql .= ' AND o.channel_id = ?';
            $params[] = $channelFilter;
        }
        if ($notes !== '') {
            $sql .= ' AND o.notes LIKE ?';
            $params[] = '%' . $notes . '%';
        }

        $sql .= ' ORDER BY o.id DESC LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $results = [];
        foreach ($rows as $row) {
            $oid = (int) ($row['id'] ?? 0);
            $createdRaw = $hasCreatedAt ? (string) ($row['created_at'] ?? '') : '';
            $invNum = trim((string) ($row['invoice_number'] ?? ''));
            $results[] = [
                'id' => $oid,
                'reference' => $invNum !== '' ? $invNum : ('INV-C-' . $oid),
                'order_number' => (string) ($row['order_number'] ?? ''),
                'created_at_dmy' => $createdRaw !== '' ? orange_format_date_dmY($createdRaw) : '',
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
                'channel_name' => orange_sales_order_channel_label(
                    isset($row['channel_id']) ? (int) $row['channel_id'] : 0,
                    (string) ($row['channel_name'] ?? '')
                ),
                'notes' => (string) ($row['notes'] ?? ''),
                'total' => (float) ($row['total'] ?? 0),
            ];
        }

        json_response(['success' => true, 'results' => $results]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تصفّح فواتير المبيعات');
}
