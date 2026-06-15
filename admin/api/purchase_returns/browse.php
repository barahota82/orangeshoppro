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
    $countrySql = orange_sql_country_and_fragment($pdo, 'purchase_returns', 'pr', $countryId);
    $hasCreatedAt = orange_table_has_column($pdo, 'purchase_returns', 'created_at');
    $hasReturnNumber = orange_table_has_column($pdo, 'purchase_returns', 'return_number');
    $hasPurchaseId = orange_table_has_column($pdo, 'purchase_returns', 'purchase_id');

    if ($action === 'nav') {
        $where = trim((string) ($data['where'] ?? ''));
        $currentId = (int) ($data['current_id'] ?? 0);
        if (!in_array($where, ['first', 'prev', 'next', 'last'], true)) {
            json_response(['success' => false, 'message' => 'اتجاه تنقل غير صالح'], 422);
        }

        $countSt = $pdo->query('SELECT COUNT(*) FROM purchase_returns pr WHERE 1=1' . $countrySql);
        if ((int) $countSt->fetchColumn() === 0) {
            json_response(['success' => false, 'message' => 'لا توجد مردودات مشتريات بعد']);
        }

        $targetId = 0;
        if ($where === 'first') {
            $targetId = (int) $pdo->query(
                'SELECT pr.id FROM purchase_returns pr WHERE 1=1' . $countrySql . ' ORDER BY pr.id ASC LIMIT 1'
            )->fetchColumn();
        } elseif ($where === 'last') {
            $targetId = (int) $pdo->query(
                'SELECT pr.id FROM purchase_returns pr WHERE 1=1' . $countrySql . ' ORDER BY pr.id DESC LIMIT 1'
            )->fetchColumn();
        } elseif ($where === 'prev') {
            if ($currentId <= 0) {
                $targetId = (int) $pdo->query(
                    'SELECT pr.id FROM purchase_returns pr WHERE 1=1' . $countrySql . ' ORDER BY pr.id DESC LIMIT 1'
                )->fetchColumn();
            } else {
                $st = $pdo->prepare(
                    'SELECT pr.id FROM purchase_returns pr WHERE 1=1' . $countrySql . ' AND pr.id < ? ORDER BY pr.id DESC LIMIT 1'
                );
                $st->execute([$currentId]);
                $targetId = (int) ($st->fetchColumn() ?: 0);
                if ($targetId <= 0) {
                    $targetId = (int) $pdo->query(
                        'SELECT pr.id FROM purchase_returns pr WHERE 1=1' . $countrySql . ' ORDER BY pr.id ASC LIMIT 1'
                    )->fetchColumn();
                }
            }
        } elseif ($where === 'next') {
            if ($currentId <= 0) {
                $targetId = (int) $pdo->query(
                    'SELECT pr.id FROM purchase_returns pr WHERE 1=1' . $countrySql . ' ORDER BY pr.id ASC LIMIT 1'
                )->fetchColumn();
            } else {
                $st = $pdo->prepare(
                    'SELECT pr.id FROM purchase_returns pr WHERE 1=1' . $countrySql . ' AND pr.id > ? ORDER BY pr.id ASC LIMIT 1'
                );
                $st->execute([$currentId]);
                $targetId = (int) ($st->fetchColumn() ?: 0);
                if ($targetId <= 0) {
                    $targetId = (int) $pdo->query(
                        'SELECT pr.id FROM purchase_returns pr WHERE 1=1' . $countrySql . ' ORDER BY pr.id DESC LIMIT 1'
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
        $purchaseRef = trim((string) ($data['purchase_ref'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        $sql = 'SELECT pr.id, pr.total, pr.notes';
        if ($hasCreatedAt) {
            $sql .= ', pr.created_at';
        }
        if ($hasReturnNumber) {
            $sql .= ', pr.return_number';
        }
        if ($hasPurchaseId) {
            $sql .= ', pr.purchase_id';
        }
        $sql .= ', s.name AS supplier_name FROM purchase_returns pr
            LEFT JOIN suppliers s ON s.id = pr.supplier_id
            WHERE 1=1' . $countrySql;
        $params = [];

        if ($idFrom > 0) {
            $sql .= ' AND pr.id >= ?';
            $params[] = $idFrom;
        }
        if ($idTo > 0) {
            $sql .= ' AND pr.id <= ?';
            $params[] = $idTo;
        }
        if ($hasCreatedAt && $dateFrom !== '') {
            $sql .= ' AND DATE(pr.created_at) >= ?';
            $params[] = $dateFrom;
        }
        if ($hasCreatedAt && $dateTo !== '') {
            $sql .= ' AND DATE(pr.created_at) <= ?';
            $params[] = $dateTo;
        }
        if ($ref !== '') {
            if (preg_match('/^PR-(\d+)$/i', $ref, $m)) {
                $sql .= ' AND pr.id = ?';
                $params[] = (int) $m[1];
            } elseif ($hasReturnNumber) {
                $sql .= ' AND (pr.return_number LIKE ? OR CONCAT(\'PR-\', pr.id) LIKE ?)';
                $params[] = '%' . $ref . '%';
                $params[] = '%' . $ref . '%';
            } else {
                $sql .= ' AND CONCAT(\'PR-\', pr.id) LIKE ?';
                $params[] = '%' . $ref . '%';
            }
        }
        if ($hasPurchaseId && $purchaseRef !== '') {
            if (preg_match('/^PUR-(\d+)$/i', $purchaseRef, $mPur)) {
                $sql .= ' AND pr.purchase_id = ?';
                $params[] = (int) $mPur[1];
            } elseif (ctype_digit($purchaseRef)) {
                $sql .= ' AND pr.purchase_id = ?';
                $params[] = (int) $purchaseRef;
            } else {
                $sql .= ' AND pr.purchase_id IN (SELECT id FROM purchases WHERE CONCAT(\'PUR-\', id) LIKE ?)';
                $params[] = '%' . $purchaseRef . '%';
            }
        }
        if ($notes !== '') {
            $sql .= ' AND pr.notes LIKE ?';
            $params[] = '%' . $notes . '%';
        }

        $sql .= ' ORDER BY pr.id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $results = [];
        foreach ($rows as $row) {
            $createdRaw = $hasCreatedAt ? (string) ($row['created_at'] ?? '') : '';
            $pid = $hasPurchaseId ? (int) ($row['purchase_id'] ?? 0) : 0;
            $results[] = [
                'id' => (int) ($row['id'] ?? 0),
                'reference' => $hasReturnNumber && trim((string) ($row['return_number'] ?? '')) !== ''
                    ? trim((string) $row['return_number'])
                    : ('PR-' . (int) ($row['id'] ?? 0)),
                'created_at_dmy' => $createdRaw !== '' ? orange_format_date_dmY($createdRaw) : '',
                'supplier_name' => (string) ($row['supplier_name'] ?? ''),
                'purchase_reference' => $pid > 0 ? ('PUR-' . $pid) : '',
                'notes' => (string) ($row['notes'] ?? ''),
                'total' => (float) ($row['total'] ?? 0),
            ];
        }

        json_response(['success' => true, 'results' => $results]);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تصفّح مردودات المشتريات');
}
