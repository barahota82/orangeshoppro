<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/date_format.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $action = trim((string) ($data['action'] ?? ''));

    $countryId = orange_admin_context_country_id($pdo);
    $countrySql = orange_sql_country_and_fragment($pdo, 'purchases', 'p', $countryId);
    $hasSupplierInvoice = orange_table_has_column($pdo, 'purchases', 'supplier_invoice_number');
    $hasCreatedAt = orange_table_has_column($pdo, 'purchases', 'created_at');

    if ($action === 'nav') {
        $where = trim((string) ($data['where'] ?? ''));
        $currentId = (int) ($data['current_id'] ?? 0);
        if (!in_array($where, ['first', 'prev', 'next', 'last'], true)) {
            json_response(['success' => false, 'message' => 'اتجاه تنقل غير صالح'], 422);
        }

        $countSt = $pdo->query('SELECT COUNT(*) FROM purchases p WHERE 1=1' . $countrySql);
        if ((int) $countSt->fetchColumn() === 0) {
            json_response(['success' => false, 'message' => 'لا توجد فواتير شراء بعد']);
        }

        $targetId = 0;
        if ($where === 'first') {
            $targetId = (int) $pdo->query(
                'SELECT p.id FROM purchases p WHERE 1=1' . $countrySql . ' ORDER BY p.id ASC LIMIT 1'
            )->fetchColumn();
        } elseif ($where === 'last') {
            $targetId = (int) $pdo->query(
                'SELECT p.id FROM purchases p WHERE 1=1' . $countrySql . ' ORDER BY p.id DESC LIMIT 1'
            )->fetchColumn();
        } elseif ($where === 'prev') {
            if ($currentId <= 0) {
                $targetId = (int) $pdo->query(
                    'SELECT p.id FROM purchases p WHERE 1=1' . $countrySql . ' ORDER BY p.id DESC LIMIT 1'
                )->fetchColumn();
            } else {
                $st = $pdo->prepare(
                    'SELECT p.id FROM purchases p WHERE 1=1' . $countrySql . ' AND p.id < ? ORDER BY p.id DESC LIMIT 1'
                );
                $st->execute([$currentId]);
                $targetId = (int) ($st->fetchColumn() ?: 0);
                if ($targetId <= 0) {
                    $targetId = (int) $pdo->query(
                        'SELECT p.id FROM purchases p WHERE 1=1' . $countrySql . ' ORDER BY p.id ASC LIMIT 1'
                    )->fetchColumn();
                }
            }
        } elseif ($where === 'next') {
            if ($currentId <= 0) {
                $targetId = (int) $pdo->query(
                    'SELECT p.id FROM purchases p WHERE 1=1' . $countrySql . ' ORDER BY p.id ASC LIMIT 1'
                )->fetchColumn();
            } else {
                $st = $pdo->prepare(
                    'SELECT p.id FROM purchases p WHERE 1=1' . $countrySql . ' AND p.id > ? ORDER BY p.id ASC LIMIT 1'
                );
                $st->execute([$currentId]);
                $targetId = (int) ($st->fetchColumn() ?: 0);
                if ($targetId <= 0) {
                    $targetId = (int) $pdo->query(
                        'SELECT p.id FROM purchases p WHERE 1=1' . $countrySql . ' ORDER BY p.id DESC LIMIT 1'
                    )->fetchColumn();
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
        $supplierInvoice = trim((string) ($data['supplier_invoice'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        $sql = 'SELECT p.id, p.total, p.notes';
        if ($hasCreatedAt) {
            $sql .= ', p.created_at';
        }
        if ($hasSupplierInvoice) {
            $sql .= ', p.supplier_invoice_number';
        }
        $sql .= ', s.name AS supplier_name FROM purchases p
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            WHERE 1=1' . $countrySql;
        $params = [];

        if ($idFrom > 0) {
            $sql .= ' AND p.id >= ?';
            $params[] = $idFrom;
        }
        if ($idTo > 0) {
            $sql .= ' AND p.id <= ?';
            $params[] = $idTo;
        }
        if ($hasCreatedAt && $dateFrom !== '') {
            $sql .= ' AND DATE(p.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($hasCreatedAt && $dateTo !== '') {
            $sql .= ' AND DATE(p.created_at) <= ?';
            $params[] = $dateTo;
        }
        if ($ref !== '') {
            if (preg_match('/^PUR-(\d+)$/i', $ref, $m)) {
                $sql .= ' AND p.id = ?';
                $params[] = (int) $m[1];
            } else {
                $sql .= ' AND CONCAT(\'PUR-\', p.id) LIKE ?';
                $params[] = '%' . $ref . '%';
            }
        }
        if ($hasSupplierInvoice && $supplierInvoice !== '') {
            $sql .= ' AND p.supplier_invoice_number LIKE ?';
            $params[] = '%' . $supplierInvoice . '%';
        }
        if ($notes !== '') {
            $sql .= ' AND p.notes LIKE ?';
            $params[] = '%' . $notes . '%';
        }

        $sql .= ' ORDER BY p.id DESC LIMIT 200';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $results = [];
        foreach ($rows as $row) {
            $createdRaw = $hasCreatedAt ? (string) ($row['created_at'] ?? '') : '';
            $results[] = [
                'id' => (int) ($row['id'] ?? 0),
                'reference' => 'PUR-' . (int) ($row['id'] ?? 0),
                'created_at_dmy' => $createdRaw !== '' ? orange_format_date_dmY($createdRaw) : '',
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'supplier_invoice_number' => $hasSupplierInvoice
                    ? trim((string) ($row['supplier_invoice_number'] ?? ''))
                    : '',
                'notes' => (string) ($row['notes'] ?? ''),
                'total' => (float) ($row['total'] ?? 0),
            ];
        }

        json_response(['success' => true, 'results' => $results]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تصفّح فواتير الشراء');
}
