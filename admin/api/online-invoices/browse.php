<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/sales_invoice_online.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/admin_time.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    $countryId = orange_admin_context_country_id($pdo);
    $countryFilter = orange_sales_invoice_online_country_filter($pdo, $countryId, 'o');
    $scopeSql = orange_sales_invoice_online_scope_sql($pdo, 'o');
    $countrySql = $countryFilter !== null ? $countryFilter['sql'] : '';
    $countryParams = $countryFilter !== null ? $countryFilter['params'] : [];

    $baseFrom = ' FROM orders o WHERE 1=1' . $countrySql . $scopeSql;

    if ($action === 'nav') {
        $where = trim((string) ($data['where'] ?? ''));
        $currentId = (int) ($data['current_id'] ?? 0);
        if (!in_array($where, ['first', 'prev', 'next', 'last'], true)) {
            json_response(['success' => false, 'message' => 'اتجاه تنقل غير صالح'], 422);
        }

        $countSt = $pdo->prepare('SELECT COUNT(*)' . $baseFrom);
        $countSt->execute($countryParams);
        if ((int) $countSt->fetchColumn() === 0) {
            json_response(['success' => false, 'message' => 'لا توجد فواتير أونلاين (INV-O) بعد']);
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
                $st = $pdo->prepare('SELECT o.id' . $baseFrom . ' AND o.id < ? ORDER BY o.id DESC LIMIT 1');
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
                $st = $pdo->prepare('SELECT o.id' . $baseFrom . ' AND o.id > ? ORDER BY o.id ASC LIMIT 1');
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
        $dateFromRaw = trim((string) ($data['date_from'] ?? ''));
        $dateToRaw = trim((string) ($data['date_to'] ?? ''));
        $dateFrom = $dateFromRaw !== '' ? orange_admin_time_date_only_normalize($dateFromRaw) : '';
        $dateTo = $dateToRaw !== '' ? orange_admin_time_date_only_normalize($dateToRaw) : '';
        if (($dateFromRaw !== '' && $dateFrom === '') || ($dateToRaw !== '' && $dateTo === '')) {
            json_response(['success' => false, 'message' => 'تاريخ البحث غير صالح'], 422);
        }
        $ref = trim((string) ($data['reference'] ?? ''));
        $orderRef = trim((string) ($data['order_ref'] ?? ''));
        $customerName = trim((string) ($data['customer_name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $channelId = (int) ($data['channel_id'] ?? 0);
        $notes = trim((string) ($data['notes'] ?? ''));

        $hasCreatedAt = orange_table_has_column($pdo, 'orders', 'created_at');
        $hasCompletedAt = orange_table_has_column($pdo, 'orders', 'completed_at');

        $sql = 'SELECT o.id, o.customer_name, o.phone, o.total, o.notes, o.channel_id, o.order_number, ch.name AS channel_name';
        if ($hasCreatedAt) {
            $sql .= ', o.created_at';
        }
        if ($hasCompletedAt) {
            $sql .= ', o.completed_at';
        }
        if (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
            $sql .= ', o.invoice_number';
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
        // Absolute Moment (UTC): Country-local civil day → half-open sargable UTC range.
        if ($hasCreatedAt && ($dateFrom !== '' || $dateTo !== '')) {
            if ($countryId <= 0) {
                json_response(['success' => false, 'message' => 'سياق الدولة مطلوب لتصفية التاريخ'], 422);
            }
            $iana = orange_admin_time_timezone_for_country_id($pdo, $countryId);
            if ($dateFrom !== '') {
                $fromBounds = orange_admin_time_day_bounds_mysql_utc($dateFrom, $iana);
                $sql .= ' AND o.created_at >= ?';
                $params[] = $fromBounds['start_utc_mysql'];
            }
            if ($dateTo !== '') {
                $toBounds = orange_admin_time_day_bounds_mysql_utc($dateTo, $iana);
                $sql .= ' AND o.created_at < ?';
                $params[] = $toBounds['end_exclusive_utc_mysql'];
            }
        }
        if ($ref !== '') {
            if (preg_match('/^INV-O-(\d+)$/i', $ref, $m)) {
                $sql .= ' AND o.id = ?';
                $params[] = (int) $m[1];
            } elseif (preg_match('/^(\d+)$/', $ref, $mNum)) {
                $sql .= ' AND o.id = ?';
                $params[] = (int) $mNum[1];
            } elseif (orange_table_has_column($pdo, 'orders', 'invoice_number')) {
                $sql .= ' AND o.invoice_number LIKE ?';
                $params[] = '%' . $ref . '%';
            }
        }
        if ($orderRef !== '') {
            $sql .= ' AND o.order_number LIKE ?';
            $params[] = '%' . $orderRef . '%';
        }
        if ($customerName !== '') {
            $sql .= ' AND o.customer_name LIKE ?';
            $params[] = '%' . $customerName . '%';
        }
        if ($phone !== '') {
            $sql .= ' AND o.phone LIKE ?';
            $params[] = '%' . $phone . '%';
        }
        if ($channelId > 0) {
            $sql .= ' AND o.channel_id = ?';
            $params[] = $channelId;
        }
        if ($notes !== '') {
            $sql .= ' AND o.notes LIKE ?';
            $params[] = '%' . $notes . '%';
        }

        $sql .= ' ORDER BY o.id DESC';
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
                'reference' => $invNum !== '' ? $invNum : ('INV-O-' . $oid),
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
    orange_admin_api_catch($e, 'تعذر تصفّح فواتير أونلاين');
}
