<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * سجل عناوين العميل (المهمة 2).
 *
 * السياسة (قرار المالك): «العنوان الحالي» للعميل يُحدَّث عند **إنشاء القيد المحاسبي للطلب**
 * (تأكيد الاستلام الفعلي) — لا عند إنشاء الطلب. كل عنوان مُستلَم يُحفظ كصف تاريخي بدل
 * الكتابة فوق السابق، فلا تُفقد بيانات قيّمة.
 *
 * المُحفِّز: نهاية orange_post_order_delivery_accounting() في includes/order_fulfillment.php.
 */

/**
 * يرقّي عنوان طلب مُسلَّم إلى «الحالي» للعميل ويضيفه إلى سجل العناوين (مرّة واحدة لكل طلب).
 *
 * @param array<string,mixed> $order صف الطلب (يجب أن يحوي customer_id/area/address/delivery_area_id/id)
 */
function orange_customer_address_promote_from_order(PDO $pdo, array $order, string $receivedAt): void
{
    if (!orange_table_exists($pdo, 'customer_addresses')) {
        return;
    }

    $customerId = (int) ($order['customer_id'] ?? 0);
    if ($customerId <= 0) {
        return;
    }

    $orderId = (int) ($order['id'] ?? 0);
    $deliveryAreaId = isset($order['delivery_area_id']) && (int) $order['delivery_area_id'] > 0
        ? (int) $order['delivery_area_id']
        : null;
    $area = trim((string) ($order['area'] ?? ''));
    $address = trim((string) ($order['address'] ?? ''));

    // لا شيء مفيد لحفظه.
    if ($area === '' && $address === '' && $deliveryAreaId === null) {
        return;
    }

    if (function_exists('mb_substr')) {
        $area = mb_substr($area, 0, 255, 'UTF-8');
        $address = mb_substr($address, 0, 2000, 'UTF-8');
    } else {
        $area = substr($area, 0, 255);
        $address = substr($address, 0, 2000);
    }

    $receivedAt = trim($receivedAt) !== '' ? $receivedAt : date('Y-m-d H:i:s');

    try {
        // idempotent: لا نُضيف نفس الطلب مرتين (UNIQUE order_id) إن أُعيد استدعاء الترحيل.
        if ($orderId > 0) {
            $chk = $pdo->prepare('SELECT id FROM customer_addresses WHERE order_id = ? LIMIT 1');
            $chk->execute([$orderId]);
            if ($chk->fetchColumn()) {
                return;
            }
        }

        $pdo->prepare('UPDATE customer_addresses SET is_current = 0 WHERE customer_id = ? AND is_current = 1')
            ->execute([$customerId]);

        $ins = $pdo->prepare(
            'INSERT INTO customer_addresses
                (customer_id, delivery_area_id, area, address, order_id, received_at, is_current)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $ins->execute([
            $customerId,
            $deliveryAreaId,
            $area,
            $address,
            $orderId > 0 ? $orderId : null,
            $receivedAt,
        ]);

        orange_customer_address_sync_current_customer($pdo, $customerId, $deliveryAreaId, $area, $address);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] customer_address promote: ' . $e->getMessage());
        }
    }
}

/**
 * يزامن «الحالي» في جدول customers (مصدر العرض في الأدمن وتعبئة الواجهة) مع آخر عنوان مُستلَم.
 */
function orange_customer_address_sync_current_customer(
    PDO $pdo,
    int $customerId,
    ?int $deliveryAreaId,
    string $area,
    string $address
): void {
    if ($customerId <= 0 || !orange_table_exists($pdo, 'customers')) {
        return;
    }

    $sets = [];
    $params = [];
    if ($area !== '' && orange_table_has_column($pdo, 'customers', 'area')) {
        $sets[] = 'area = ?';
        $params[] = $area;
    }
    if ($deliveryAreaId !== null && orange_table_has_column($pdo, 'customers', 'delivery_area_id')) {
        $sets[] = 'delivery_area_id = ?';
        $params[] = $deliveryAreaId;
    }
    if ($address !== '' && orange_table_has_column($pdo, 'customers', 'address')) {
        $sets[] = 'address = ?';
        $params[] = $address;
    }
    if ($sets === []) {
        return;
    }

    $params[] = $customerId;
    $pdo->prepare('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
}

/**
 * آخر عنوان «حالي» (أو الأحدث استلاماً) للعميل — للتعبئة المسبقة في الإتمام.
 *
 * @return array{delivery_area_id:?int, area:string, address:string}|null
 */
function orange_customer_address_current_row(PDO $pdo, int $customerId): ?array
{
    if ($customerId <= 0 || !orange_table_exists($pdo, 'customer_addresses')) {
        return null;
    }

    try {
        $st = $pdo->prepare(
            'SELECT delivery_area_id, area, address
             FROM customer_addresses
             WHERE customer_id = ?
             ORDER BY is_current DESC, received_at DESC, id DESC
             LIMIT 1'
        );
        $st->execute([$customerId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'delivery_area_id' => isset($row['delivery_area_id']) && (int) $row['delivery_area_id'] > 0
                ? (int) $row['delivery_area_id']
                : null,
            'area' => (string) ($row['area'] ?? ''),
            'address' => (string) ($row['address'] ?? ''),
        ];
    } catch (Throwable $e) {
        return null;
    }
}
