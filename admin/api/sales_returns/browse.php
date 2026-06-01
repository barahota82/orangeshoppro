<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_once __DIR__ . '/../../../includes/sales_return_analytics.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    $countryId = orange_admin_context_country_id($pdo);
    $hasCreatedAt = orange_table_has_column($pdo, 'sales_returns', 'created_at');
    $hasReturnNumber = orange_table_has_column($pdo, 'sales_returns', 'return_number');
    $hasOrderId = orange_table_has_column($pdo, 'sales_returns', 'order_id');
    $hasInvoiceRef = orange_table_has_column($pdo, 'sales_returns', 'invoice_reference');
    $hasSourceKind = orange_table_has_column($pdo, 'sales_returns', 'source_kind');
    $hasCustomers = orange_table_exists($pdo, 'customers');
    $custNameCol = 'name_ar';
    if ($hasCustomers) {
        if (!orange_table_has_column($pdo, 'customers', 'name_ar')) {
            $custNameCol = orange_table_has_column($pdo, 'customers', 'name') ? 'name' : 'name_ar';
        }
    }

    $countrySql = '';
    if ($countryId > 0) {
        if (orange_table_has_country_id($pdo, 'sales_returns')) {
            $countrySql = ' AND sr.country_id = ' . (int) $countryId;
        } else {
            $countryParts = [];
            if ($hasCustomers && orange_table_has_country_id($pdo, 'customers')) {
                $countryParts[] = 'c.country_id = ' . (int) $countryId;
            }
            if (orange_table_has_country_id($pdo, 'orders')) {
                $countryParts[] = '(sr.customer_id IS NULL AND o.country_id = ' . (int) $countryId . ')';
            }
            if ($countryParts !== []) {
                $countrySql = ' AND (' . implode(' OR ', $countryParts) . ')';
            }
        }
    }

    $joinSql = '';
    if ($hasCustomers) {
        $joinSql .= ' LEFT JOIN customers c ON c.id = sr.customer_id';
    }
    if (orange_table_exists($pdo, 'orders')) {
        $joinSql .= ' LEFT JOIN orders o ON o.id = sr.order_id';
    }
    $baseFrom = ' FROM sales_returns sr' . $joinSql . ' WHERE 1=1' . $countrySql;

    if ($action === 'nav') {
        $where = trim((string) ($data['where'] ?? ''));
        $currentId = (int) ($data['current_id'] ?? 0);
        if (!in_array($where, ['first', 'prev', 'next', 'last'], true)) {
            json_response(['success' => false, 'message' => 'اتجاه تنقل غير صالح'], 422);
        }

        $countSt = $pdo->query('SELECT COUNT(*)' . $baseFrom);
        if ((int) $countSt->fetchColumn() === 0) {
            json_response(['success' => false, 'message' => 'لا توجد مردودات مبيعات بعد']);
        }

        $targetId = 0;
        if ($where === 'first') {
            $targetId = (int) $pdo->query(
                'SELECT sr.id' . $baseFrom . ' ORDER BY sr.id ASC LIMIT 1'
            )->fetchColumn();
        } elseif ($where === 'last') {
            $targetId = (int) $pdo->query(
                'SELECT sr.id' . $baseFrom . ' ORDER BY sr.id DESC LIMIT 1'
            )->fetchColumn();
        } elseif ($where === 'prev') {
            if ($currentId <= 0) {
                $targetId = (int) $pdo->query(
                    'SELECT sr.id' . $baseFrom . ' ORDER BY sr.id DESC LIMIT 1'
                )->fetchColumn();
            } else {
                $st = $pdo->prepare(
                    'SELECT sr.id' . $baseFrom . ' AND sr.id < ? ORDER BY sr.id DESC LIMIT 1'
                );
                $st->execute([$currentId]);
                $targetId = (int) ($st->fetchColumn() ?: 0);
                if ($targetId <= 0) {
                    $targetId = (int) $pdo->query(
                        'SELECT sr.id' . $baseFrom . ' ORDER BY sr.id ASC LIMIT 1'
                    )->fetchColumn();
                }
            }
        } elseif ($where === 'next') {
            if ($currentId <= 0) {
                $targetId = (int) $pdo->query(
                    'SELECT sr.id' . $baseFrom . ' ORDER BY sr.id ASC LIMIT 1'
                )->fetchColumn();
            } else {
                $st = $pdo->prepare(
                    'SELECT sr.id' . $baseFrom . ' AND sr.id > ? ORDER BY sr.id ASC LIMIT 1'
                );
                $st->execute([$currentId]);
                $targetId = (int) ($st->fetchColumn() ?: 0);
                if ($targetId <= 0) {
                    $targetId = (int) $pdo->query(
                        'SELECT sr.id' . $baseFrom . ' ORDER BY sr.id DESC LIMIT 1'
                    )->fetchColumn();
                }
            }
        }

        if ($targetId <= 0) {
            json_response(['success' => false, 'message' => 'لا يوجد مردود في هذا الاتجاه']);
        }

        json_response(['success' => true, 'id' => $targetId]);
    }

    if ($action === 'search') {
        $idFrom = (int) ($data['id_from'] ?? 0);
        $idTo = (int) ($data['id_to'] ?? 0);
        $dateFrom = trim((string) ($data['date_from'] ?? ''));
        $dateTo = trim((string) ($data['date_to'] ?? ''));
        $ref = trim((string) ($data['reference'] ?? ''));
        $orderRef = trim((string) ($data['order_ref'] ?? ''));
        $customerQ = trim((string) ($data['customer'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        $sql = 'SELECT sr.id, sr.total, sr.notes, sr.type';
        if ($hasCreatedAt) {
            $sql .= ', sr.created_at';
        }
        if ($hasReturnNumber) {
            $sql .= ', sr.return_number';
        }
        if ($hasOrderId) {
            $sql .= ', sr.order_id';
        }
        if ($hasInvoiceRef) {
            $sql .= ', sr.invoice_reference';
        }
        if ($hasSourceKind) {
            $sql .= ', sr.source_kind';
        }
        if ($hasCustomers) {
            $sql .= ', c.' . $custNameCol . ' AS customer_name';
        }
        $sql .= $baseFrom;
        $params = [];

        if ($idFrom > 0) {
            $sql .= ' AND sr.id >= ?';
            $params[] = $idFrom;
        }
        if ($idTo > 0) {
            $sql .= ' AND sr.id <= ?';
            $params[] = $idTo;
        }
        if ($hasCreatedAt && $dateFrom !== '') {
            $sql .= ' AND DATE(sr.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($hasCreatedAt && $dateTo !== '') {
            $sql .= ' AND DATE(sr.created_at) <= ?';
            $params[] = $dateTo;
        }
        if ($ref !== '') {
            if (preg_match('/^SR-(\d+)$/i', $ref, $m)) {
                $sql .= ' AND sr.id = ?';
                $params[] = (int) $m[1];
            } elseif ($hasReturnNumber) {
                $sql .= ' AND (sr.return_number LIKE ? OR CONCAT(\'SR-\', sr.id) LIKE ?)';
                $params[] = '%' . $ref . '%';
                $params[] = '%' . $ref . '%';
            } else {
                $sql .= ' AND CONCAT(\'SR-\', sr.id) LIKE ?';
                $params[] = '%' . $ref . '%';
            }
        }
        if ($hasOrderId && $orderRef !== '') {
            if (preg_match('/^INV-C-(\d+)$/i', $orderRef, $mInv)) {
                $sql .= ' AND sr.order_id = ?';
                $params[] = (int) $mInv[1];
            } elseif (preg_match('/^INV-O-(\d+)$/i', $orderRef, $mInvO)) {
                $sql .= ' AND sr.order_id = ?';
                $params[] = (int) $mInvO[1];
            } elseif (ctype_digit($orderRef)) {
                $sql .= ' AND sr.order_id = ?';
                $params[] = (int) $orderRef;
            } elseif (orange_table_exists($pdo, 'orders')) {
                $sql .= ' AND sr.order_id IN (SELECT id FROM orders WHERE order_number LIKE ? OR invoice_number LIKE ?)';
                $params[] = '%' . $orderRef . '%';
                $params[] = '%' . $orderRef . '%';
            }
        }
        if ($customerQ !== '' && $hasCustomers) {
            $sql .= ' AND (c.' . $custNameCol . ' LIKE ? OR CAST(sr.customer_id AS CHAR) LIKE ?)';
            $params[] = '%' . $customerQ . '%';
            $params[] = '%' . $customerQ . '%';
        }
        if ($notes !== '') {
            $sql .= ' AND sr.notes LIKE ?';
            $params[] = '%' . $notes . '%';
        }

        $sql .= ' ORDER BY sr.id DESC LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $results = [];
        foreach ($rows as $row) {
            $createdRaw = $hasCreatedAt ? (string) ($row['created_at'] ?? '') : '';
            $oid = $hasOrderId ? (int) ($row['order_id'] ?? 0) : 0;
            $invRef = $hasInvoiceRef ? trim((string) ($row['invoice_reference'] ?? '')) : '';
            if ($invRef === '' && $oid > 0) {
                $invRef = 'INV-C-' . $oid;
            }
            $sk = $hasSourceKind ? trim((string) ($row['source_kind'] ?? '')) : '';
            $results[] = [
                'id' => (int) ($row['id'] ?? 0),
                'reference' => $hasReturnNumber && trim((string) ($row['return_number'] ?? '')) !== ''
                    ? trim((string) $row['return_number'])
                    : ('SR-' . (int) ($row['id'] ?? 0)),
                'created_at_dmy' => $createdRaw !== '' ? orange_format_date_dmY($createdRaw) : '',
                'customer_name' => (string) ($row['customer_name'] ?? ''),
                'channel_label' => orange_sales_return_payment_type_label((string) ($row['type'] ?? 'cash')),
                'source_kind_label' => orange_sales_return_source_kind_label($sk),
                'order_reference' => $invRef,
                'notes' => (string) ($row['notes'] ?? ''),
                'total' => (float) ($row['total'] ?? 0),
            ];
        }

        json_response(['success' => true, 'results' => $results]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تصفّح مردودات المبيعات');
}
