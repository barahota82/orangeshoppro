<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/party_subledger.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

/**
 * س15 + بند 10 + شاشة العملاء الاحترافية:
 *
 * يدعم وضعين:
 * 1) GET q=نص — بحث مختصر يُرجع id/code/name/phone/area لاستخدامه في picker شاشة كشف الحساب الموحّدة.
 * 2) GET full=1 — يُرجع كامل صفوف العملاء بكل الحقول + الرصيد + اسم المنطقة + storefront_account_id،
 *    لتُحمَّل دفعةً واحدة في payload شاشة admin/pages/customers.php (نمط الموردين).
 */
try {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $adminCountryId = orange_admin_context_country_id($pdo);
    $custCountryFilter = orange_sql_filter_country_id($pdo, 'customers', 'c', $adminCountryId);

    if (!orange_table_exists($pdo, 'customers')) {
        json_response(['success' => false, 'message' => 'جدول العملاء غير متوفر'], 500);
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    $full = isset($_GET['full']) && (string) $_GET['full'] === '1';
    $picker = isset($_GET['picker']) && (string) $_GET['picker'] === '1';

    $hasCode = orange_table_has_column($pdo, 'customers', 'code');
    $hasArea = orange_table_has_column($pdo, 'customers', 'area');
    $hasAddress = orange_table_has_column($pdo, 'customers', 'address');
    $hasEmail = orange_table_has_column($pdo, 'customers', 'email');
    $hasNotes = orange_table_has_column($pdo, 'customers', 'notes');
    $hasLimit = orange_table_has_column($pdo, 'customers', 'credit_limit');
    $hasDial = orange_table_has_column($pdo, 'customers', 'phone_country_dial');
    $hasNat = orange_table_has_column($pdo, 'customers', 'phone_national');
    $hasDaId = orange_table_has_column($pdo, 'customers', 'delivery_area_id');
    $hasCivilId = orange_table_has_column($pdo, 'customers', 'civil_id');

    // النمط المختصر (للـ picker القديم في partner_account_statement).
    if (!$full) {
        $cols = 'c.id, c.name_ar AS name, c.phone';
        if ($hasCode) {
            $cols .= ', c.code';
        }
        if ($hasArea) {
            $cols .= ', c.area';
        }
        $sql = 'SELECT ' . $cols . ' FROM customers c';
        $params = [];
        $whereParts = [];
        if ($custCountryFilter !== null) {
            $whereParts[] = ltrim($custCountryFilter['sql'], ' AND ');
            $params[] = $custCountryFilter['param'];
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $conds = ['c.name_ar LIKE ?', 'c.phone LIKE ?'];
            $params[] = $like;
            $params[] = $like;
            if ($hasCode) {
                $conds[] = 'c.code LIKE ?';
                $params[] = $like;
            }
            $whereParts[] = '(' . implode(' OR ', $conds) . ')';
        }
        if ($whereParts !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $whereParts);
        }
        $sql .= ' ORDER BY c.id DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'code' => $hasCode ? (string) ($r['code'] ?? '') : '',
                'name' => (string) ($r['name'] ?? ''),
                'phone' => (string) ($r['phone'] ?? ''),
                'area' => $hasArea ? (string) ($r['area'] ?? '') : '',
                'current_balance' => $picker
                    ? round((float) orange_party_balance_customer($pdo, $id), 3)
                    : null,
            ];
        }
        json_response(['success' => true, 'customers' => $out]);
    }

    // النمط الكامل: يُرجع كل العملاء + معلومات إضافية لشاشة customers.php الاحترافية.
    $hasStorefrontAccountsLink = orange_table_exists($pdo, 'storefront_accounts')
        && orange_table_has_column($pdo, 'storefront_accounts', 'customer_id');
    $hasOrdersLink = orange_table_exists($pdo, 'orders')
        && orange_table_has_column($pdo, 'orders', 'customer_id');

    // اسم المنطقة عند ربطه بـ delivery_area_id.
    $areaIndex = [];
    if ($hasDaId && orange_table_exists($pdo, 'delivery_areas')) {
        $daSql = 'SELECT id, name_ar, name_en, is_active FROM delivery_areas';
        $daParams = [];
        if (orange_delivery_areas_has_country_column($pdo) && $adminCountryId > 0) {
            $daSql .= ' WHERE country_id = ?';
            $daParams[] = $adminCountryId;
        }
        if ($daParams === []) {
            $daSt = $pdo->query($daSql);
        } else {
            $daSt = $pdo->prepare($daSql);
            $daSt->execute($daParams);
        }
        if ($daSt) {
            while ($daRow = $daSt->fetch(PDO::FETCH_ASSOC)) {
                $areaIndex[(int) $daRow['id']] = [
                    'name_ar' => (string) ($daRow['name_ar'] ?? ''),
                    'name_en' => (string) ($daRow['name_en'] ?? ''),
                    'is_active' => (int) ($daRow['is_active'] ?? 0) === 1,
                ];
            }
        }
    }

    $custListSql = 'SELECT c.* FROM customers c WHERE 1=1';
    $custListParams = [];
    if ($custCountryFilter !== null) {
        $custListSql .= $custCountryFilter['sql'];
        $custListParams[] = $custCountryFilter['param'];
    }
    if ($adminCountryId > 0
        && orange_table_exists($pdo, 'storefront_accounts')
        && orange_table_has_column($pdo, 'storefront_accounts', 'customer_id')
        && orange_table_has_column($pdo, 'storefront_accounts', 'country_id')) {
        $custListSql .= ' AND NOT EXISTS (
            SELECT 1 FROM storefront_accounts sa_cc
            WHERE sa_cc.customer_id = c.id
              AND sa_cc.country_id IS NOT NULL AND sa_cc.country_id > 0
              AND sa_cc.country_id <> ?
        )';
        $custListParams[] = $adminCountryId;
    }
    $custListSql .= ' ORDER BY c.id ASC';
    if ($custListParams === []) {
        $cust = $pdo->query($custListSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $custListSt = $pdo->prepare($custListSql);
        $custListSt->execute($custListParams);
        $cust = $custListSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // معرّفات حسابات الواجهة والمعرّفات الكاملة (load once).
    $sfAccountByCustomerId = [];
    if ($hasStorefrontAccountsLink) {
        $sfSql = 'SELECT sa.id, sa.customer_id, sa.email, sa.email_verified_at, sa.registered_channel_slug
             FROM storefront_accounts sa';
        $sfParams = [];
        if ($adminCountryId > 0) {
            $sfScope = orange_sql_filter_storefront_row_belongs_to_country(
                $pdo,
                'sa',
                'registered_channel_slug',
                $adminCountryId
            );
            if ($sfScope !== null) {
                $sfSql .= ' WHERE sa.customer_id IS NOT NULL' . $sfScope['where'];
                $sfParams = $sfScope['params'];
            } elseif (orange_table_has_column($pdo, 'storefront_accounts', 'country_id')) {
                $sfSql .= ' WHERE sa.customer_id IS NOT NULL AND sa.country_id = ?';
                $sfParams[] = $adminCountryId;
            } else {
                $sfSql .= ' WHERE sa.customer_id IS NOT NULL';
            }
        } else {
            $sfSql .= ' WHERE sa.customer_id IS NOT NULL';
        }
        if ($sfParams === []) {
            $sfSt = $pdo->query($sfSql);
        } else {
            $sfSt = $pdo->prepare($sfSql);
            $sfSt->execute($sfParams);
        }
        if ($sfSt) {
            while ($sfRow = $sfSt->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int) ($sfRow['customer_id'] ?? 0);
                if ($cid > 0 && !isset($sfAccountByCustomerId[$cid])) {
                    $sfAccountByCustomerId[$cid] = [
                        'id' => (int) ($sfRow['id'] ?? 0),
                        'email' => (string) ($sfRow['email'] ?? ''),
                        'verified' => !empty($sfRow['email_verified_at']),
                        'channel' => (string) ($sfRow['registered_channel_slug'] ?? ''),
                    ];
                }
            }
        }
    }

    // إحصاءات الطلبات لكل عميل (count + last date) عبر استعلام واحد.
    $orderStatsByCustomerId = [];
    if ($hasOrdersLink) {
        try {
            $ordersCountrySql = orange_sql_country_and_fragment($pdo, 'orders', '', $adminCountryId);
            $oSt = $pdo->query(
                'SELECT customer_id, COUNT(*) AS cnt, MAX(created_at) AS last_at
                 FROM orders WHERE customer_id IS NOT NULL AND customer_id > 0'
                . $ordersCountrySql
                . ' GROUP BY customer_id'
            );
            if ($oSt) {
                while ($oRow = $oSt->fetch(PDO::FETCH_ASSOC)) {
                    $cid = (int) ($oRow['customer_id'] ?? 0);
                    if ($cid > 0) {
                        $orderStatsByCustomerId[$cid] = [
                            'count' => (int) ($oRow['cnt'] ?? 0),
                            'last_at' => (string) ($oRow['last_at'] ?? ''),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] customers/search full order stats: ' . $e->getMessage());
            }
        }
    }

    $out = [];
    foreach ($cust as $r) {
        $cid = (int) ($r['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $bal = orange_party_balance_customer($pdo, $cid);
        $daId = $hasDaId && isset($r['delivery_area_id']) ? (int) $r['delivery_area_id'] : 0;
        $daName = '';
        $daActive = true;
        if ($daId > 0 && isset($areaIndex[$daId])) {
            $aRow = $areaIndex[$daId];
            $daName = $aRow['name_ar'] !== '' ? $aRow['name_ar'] : $aRow['name_en'];
            $daActive = (bool) $aRow['is_active'];
        }
        $stats = $orderStatsByCustomerId[$cid] ?? ['count' => 0, 'last_at' => ''];
        $sfAcc = $sfAccountByCustomerId[$cid] ?? null;

        $searchHayRaw = trim(
            ($hasCode ? (string) ($r['code'] ?? '') : '') . ' ' .
            (string) ($r['name_ar'] ?? '') . ' ' .
            (string) ($r['phone'] ?? '') . ' ' .
            ($hasEmail ? (string) ($r['email'] ?? '') : '') . ' ' .
            ($hasArea ? (string) ($r['area'] ?? '') : '') . ' ' .
            $daName . ' ' .
            ($hasNotes ? (string) ($r['notes'] ?? '') : '')
        );
        $searchHay = function_exists('mb_strtolower') ? mb_strtolower($searchHayRaw, 'UTF-8') : strtolower($searchHayRaw);

        $out[] = [
            'id' => $cid,
            'code' => $hasCode ? (string) ($r['code'] ?? '') : '',
            'name_ar' => (string) ($r['name_ar'] ?? ''),
            'phone' => (string) ($r['phone'] ?? ''),
            'phone_country_dial' => $hasDial ? (string) ($r['phone_country_dial'] ?? '') : '',
            'phone_national' => $hasNat ? (string) ($r['phone_national'] ?? '') : '',
            'email' => $hasEmail ? (string) ($r['email'] ?? '') : '',
            'civil_id' => $hasCivilId ? (string) ($r['civil_id'] ?? '') : '',
            'area' => $hasArea ? (string) ($r['area'] ?? '') : '',
            'delivery_area_id' => $daId > 0 ? $daId : null,
            'delivery_area_name' => $daName,
            'delivery_area_active' => $daActive,
            'address' => $hasAddress ? (string) ($r['address'] ?? '') : '',
            'credit_limit' => $hasLimit && isset($r['credit_limit']) && $r['credit_limit'] !== null && (float) $r['credit_limit'] > 0
                ? (float) $r['credit_limit'] : null,
            'notes' => $hasNotes ? (string) ($r['notes'] ?? '') : '',
            'current_balance' => round((float) $bal, 3),
            'orders_count' => (int) ($stats['count'] ?? 0),
            'orders_last_at' => (string) ($stats['last_at'] ?? ''),
            'storefront_account_id' => $sfAcc ? (int) $sfAcc['id'] : null,
            'storefront_account_email' => $sfAcc ? (string) $sfAcc['email'] : '',
            'storefront_account_verified' => $sfAcc ? (bool) $sfAcc['verified'] : false,
            'storefront_account_channel' => $sfAcc ? (string) $sfAcc['channel'] : '',
            'created_at' => (string) ($r['created_at'] ?? ''),
            'search_text' => $searchHay,
        ];
    }

    json_response(['success' => true, 'customers' => $out]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر البحث في العملاء');
}
