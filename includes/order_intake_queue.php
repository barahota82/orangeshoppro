<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/order_stock.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/storefront_account.php';
require_once __DIR__ . '/delivery_areas.php';
require_once __DIR__ . '/storefront_cart_items.php';
require_once __DIR__ . '/cart_promotions.php';

function orange_order_intake_snip_message(string $msg, int $max = 500): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($msg, 0, $max, 'UTF-8');
    }

    return substr($msg, 0, $max);
}

/**
 * نص خطأ آمن لحقل order_intake_queue.error_message (لا يُخزَّن نص PDO/SQL).
 */
function orange_order_intake_error_for_queue(Throwable $e): string
{
    if ($e instanceof RuntimeException) {
        return orange_order_intake_snip_message($e->getMessage());
    }
    if (function_exists('error_log')) {
        error_log(
            '[orange] order_intake checkout: ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine()
        );
    }

    return 'تعذر إتمام الطلب. يرجى المحاولة لاحقاً أو التواصل مع المتجر.';
}

/**
 * Upsert customer by phone for storefront checkout (phone = unique key for the customer row).
 * Updates name, area, address, email from the latest order; appends order notes to customer notes.
 */
function orange_storefront_upsert_customer_from_checkout(
    PDO $pdo,
    string $name,
    string $phone,
    ?string $phoneCountryDial,
    ?string $phoneNational,
    string $area,
    string $address,
    string $emailRaw,
    string $orderNotes,
    string $orderNumber
): ?int {
    if (!orange_table_exists($pdo, 'customers')) {
        return null;
    }
    $phone = trim($phone);
    if ($phone === '') {
        return null;
    }
    $nameAr = trim($name) !== '' ? trim($name) : 'عميل';
    $area = trim($area);
    $address = trim($address);
    $email = trim($emailRaw);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }
    $emailSql = $email !== '' ? $email : null;

    $snippet = preg_replace('/\s+/u', ' ', trim($orderNotes));
    if (function_exists('mb_substr')) {
        $snippet = $snippet !== '' ? mb_substr($snippet, 0, 500, 'UTF-8') : '—';
    } else {
        $snippet = $snippet !== '' ? substr($snippet, 0, 500) : '—';
    }
    $appendLine = '[' . date('Y-m-d H:i') . '] ' . $orderNumber . ': ' . $snippet;

    $hasArea = orange_table_has_column($pdo, 'customers', 'area');
    $hasAddress = orange_table_has_column($pdo, 'customers', 'address');
    $hasEmail = orange_table_has_column($pdo, 'customers', 'email');
    $hasCustDial = orange_table_has_column($pdo, 'customers', 'phone_country_dial');
    $hasCustNat = orange_table_has_column($pdo, 'customers', 'phone_national');
    $dialSql = ($phoneCountryDial !== null && $phoneCountryDial !== '') ? substr(preg_replace('/\D+/', '', $phoneCountryDial), 0, 8) : null;
    $dialSql = ($dialSql !== null && $dialSql !== '') ? $dialSql : null;
    $natSql = ($phoneNational !== null && $phoneNational !== '') ? substr(preg_replace('/\D+/', '', $phoneNational), 0, 32) : null;
    $natSql = ($natSql !== null && $natSql !== '') ? $natSql : null;

    $find = $pdo->prepare('SELECT id, notes FROM customers WHERE phone = ? LIMIT 1');
    $find->execute([$phone]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);

    $mergeNotes = static function (?string $prev, string $line): string {
        $base = trim((string) $prev);
        $add = trim($line);
        $out = $base === '' ? $add : ($base . "\n" . $add);
        if (function_exists('mb_strlen') && mb_strlen($out, 'UTF-8') > 60000) {
            $out = mb_substr($out, -60000, null, 'UTF-8');
        } elseif (strlen($out) > 60000) {
            $out = substr($out, -60000);
        }

        return $out;
    };

    if ($existing !== false && $existing !== null) {
        $id = (int) $existing['id'];
        $newNotes = $mergeNotes($existing['notes'] ?? null, $appendLine);

        $set = ['name_ar = ?'];
        $params = [$nameAr];
        if ($hasArea) {
            $set[] = 'area = ?';
            $params[] = $area;
        }
        if ($hasAddress) {
            $set[] = 'address = ?';
            $params[] = $address;
        }
        if ($hasEmail && $emailSql !== null) {
            $set[] = 'email = ?';
            $params[] = $emailSql;
        }
        if ($hasCustDial) {
            $set[] = 'phone_country_dial = ?';
            $params[] = $dialSql;
        }
        if ($hasCustNat) {
            $set[] = 'phone_national = ?';
            $params[] = $natSql;
        }
        $set[] = 'notes = ?';
        $params[] = $newNotes;
        $params[] = $id;
        $pdo->prepare('UPDATE customers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

        return $id;
    }

    $newNotes = $mergeNotes(null, $appendLine);
    $cols = ['name_ar', 'phone'];
    $placeholders = ['?', '?'];
    $params = [$nameAr, $phone];
    if ($hasArea) {
        $cols[] = 'area';
        $placeholders[] = '?';
        $params[] = $area;
    }
    if ($hasAddress) {
        $cols[] = 'address';
        $placeholders[] = '?';
        $params[] = $address;
    }
    if ($hasEmail) {
        $cols[] = 'email';
        $placeholders[] = '?';
        $params[] = $emailSql;
    }
    if ($hasCustDial) {
        $cols[] = 'phone_country_dial';
        $placeholders[] = '?';
        $params[] = $dialSql;
    }
    if ($hasCustNat) {
        $cols[] = 'phone_national';
        $placeholders[] = '?';
        $params[] = $natSql;
    }
    $cols[] = 'notes';
    $placeholders[] = '?';
    $params[] = $newNotes;

    try {
        $sql = 'INSERT INTO customers (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $pdo->prepare($sql)->execute($params);

        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        $dup = (int) (($e->errorInfo[1] ?? 0));
        if ($dup === 1062 || str_contains($e->getMessage(), 'Duplicate')) {
            $find2 = $pdo->prepare('SELECT id, notes FROM customers WHERE phone = ? LIMIT 1');
            $find2->execute([$phone]);
            $ex2 = $find2->fetch(PDO::FETCH_ASSOC);
            if ($ex2 !== false && $ex2 !== null) {
                $id = (int) $ex2['id'];
                $newNotes2 = $mergeNotes($ex2['notes'] ?? null, $appendLine);
                $set = ['name_ar = ?'];
                $params2 = [$nameAr];
                if ($hasArea) {
                    $set[] = 'area = ?';
                    $params2[] = $area;
                }
                if ($hasAddress) {
                    $set[] = 'address = ?';
                    $params2[] = $address;
                }
                if ($hasEmail && $emailSql !== null) {
                    $set[] = 'email = ?';
                    $params2[] = $emailSql;
                }
                if ($hasCustDial) {
                    $set[] = 'phone_country_dial = ?';
                    $params2[] = $dialSql;
                }
                if ($hasCustNat) {
                    $set[] = 'phone_national = ?';
                    $params2[] = $natSql;
                }
                $set[] = 'notes = ?';
                $params2[] = $newNotes2;
                $params2[] = $id;
                $pdo->prepare('UPDATE customers SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params2);

                return $id;
            }
        }
        throw $e;
    }
}

/**
 * إدراج أسطر order_items من ناتج orange_storefront_validate_cart_items_core (داخل معاملة).
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_storefront_insert_order_items_for_order(PDO $pdo, int $orderId, array $validatedItems): void
{
    $hasVariantCol = orange_table_has_column($pdo, 'order_items', 'variant_id');
    if ($hasVariantCol) {
        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (
                order_id, product_id, variant_id, product_name, color, size, qty, price, cost
            ) VALUES (?,?,?,?,?,?,?,?,?)'
        );
    } else {
        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (
                order_id, product_id, product_name, color, size, qty, price, cost
            ) VALUES (?,?,?,?,?,?,?,?)'
        );
    }
    foreach ($validatedItems as $row) {
        if ($hasVariantCol) {
            $itemStmt->execute([
                $orderId,
                (int) $row['product']['id'],
                (int) ($row['variant_id'] ?? 0) ?: null,
                $row['product']['name'],
                $row['color'],
                $row['size'],
                $row['qty'],
                $row['price'],
                $row['cost'],
            ]);
        } else {
            $itemStmt->execute([
                $orderId,
                (int) $row['product']['id'],
                $row['product']['name'],
                $row['color'],
                $row['size'],
                $row['qty'],
                $row['price'],
                $row['cost'],
            ]);
        }
    }
}

/**
 * Core checkout: validate cart, insert order + lines, then reserve variant stock for the web queue.
 * Stock: orange_order_apply_pending_stock_reservation() inserts pending_order movements and decrements
 * product_variants.stock_quantity; release on cancel/reject via orange_order_release_pending_stock_reservation().
 * Must run inside caller transaction (no begin/commit).
 *
 * @param array<string,mixed> $data
 * @return array{order_id:int,order_number:string,total:float,whatsapp_number:string,whatsapp_url:string}
 */
function orange_storefront_execute_checkout_payload(PDO $pdo, array $data): array
{
    $langCheckout = isset($data['lang']) ? (string) $data['lang'] : 'en';
    if (!preg_match('/^(ar|en|fil|hi)$/', $langCheckout)) {
        $langCheckout = 'en';
    }
    orange_storefront_normalize_delivery_area_payload($pdo, $data, $langCheckout);

    require_fields($data, ['name', 'phone', 'area', 'address', 'channel_id', 'items']);

    $emailCheck = trim((string) ($data['email'] ?? ''));
    if ($emailCheck !== '' && !filter_var($emailCheck, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(function_exists('t') ? t('checkout_invalid_email') : 'Invalid email.');
    }
    $data['email'] = $emailCheck;

    $phoneCc = trim((string) ($data['phone_country'] ?? ''));
    $phoneCc = $phoneCc === '' ? null : $phoneCc;
    $phoneNorm = orange_normalize_customer_phone(trim((string) ($data['phone'] ?? '')), $phoneCc);
    if ($phoneNorm === null) {
        throw new RuntimeException(function_exists('t') ? t('checkout_invalid_phone') : 'Invalid phone.');
    }
    $data['phone'] = $phoneNorm;

    $dialStore = $data['phone_country_dial'] ?? null;
    $natStore = $data['phone_national'] ?? null;
    if ($dialStore === null || $dialStore === '') {
        if ($phoneCc !== null) {
            $d = preg_replace('/\D+/', '', (string) $phoneCc);
            $dialStore = ($d !== '') ? substr($d, 0, 8) : null;
        }
    }
    if (($natStore === null || $natStore === '') && $dialStore !== null && $dialStore !== '') {
        $rec = orange_storefront_national_from_e164($phoneNorm, (string) $dialStore);
        if ($rec !== null) {
            $natStore = $rec;
        }
    }
    $data['phone_country_dial'] = ($dialStore !== null && (string) $dialStore !== '') ? (string) $dialStore : null;
    $data['phone_national'] = ($natStore !== null && (string) $natStore !== '') ? (string) $natStore : null;

    if (!is_array($data['items']) || count($data['items']) === 0) {
        throw new RuntimeException('Cart items are required');
    }

    $channelStmt = $pdo->prepare('SELECT * FROM channels WHERE id = ? AND is_active = 1 LIMIT 1');
    $channelStmt->execute([(int) $data['channel_id']]);
    $channel = $channelStmt->fetch(PDO::FETCH_ASSOC);
    if (!$channel) {
        throw new RuntimeException('Invalid channel');
    }

    $orderNumber = generate_order_number();

    [$subtotal, $validatedItems] = orange_storefront_validate_cart_items_core($pdo, $data['items'], true);

    $paymentTerms = 'cash';
    if (isset($data['payment_terms'])) {
        $pt = orange_normalize_payment_terms($data['payment_terms']);
        $paymentTerms = ($pt === 'online') ? 'online' : 'cash';
    }
    $hasSource = orange_table_has_column($pdo, 'orders', 'order_source');
    $hasPay = orange_table_has_column($pdo, 'orders', 'payment_terms');
    $hasCustomerId = orange_table_has_column($pdo, 'orders', 'customer_id');
    $hasSfa = orange_table_has_column($pdo, 'orders', 'storefront_account_id');
    $sfaLink = null;
    if ($hasSfa && isset($data['storefront_account_id'])) {
        $sfaLink = orange_storefront_resolve_order_account_link($pdo, (int) $data['storefront_account_id'], $data['phone']);
    }

    $buyerRegistered = $sfaLink !== null && $sfaLink > 0;
    $promoPick = orange_cart_promotion_resolve($pdo, $subtotal, $buyerRegistered);
    $promoDiscount = $promoPick !== null ? (float) $promoPick['discount'] : 0.0;
    $promoId = $promoPick !== null ? (int) $promoPick['id'] : null;
    $orderTotal = max(0.0, round($subtotal - $promoDiscount, 4));

    $customerRowId = orange_storefront_upsert_customer_from_checkout(
        $pdo,
        trim((string) $data['name']),
        trim((string) $data['phone']),
        $data['phone_country_dial'] ?? null,
        $data['phone_national'] ?? null,
        trim((string) $data['area']),
        trim((string) $data['address']),
        trim((string) $data['email']),
        isset($data['notes']) ? trim((string) $data['notes']) : '',
        $orderNumber
    );

    $hasOrdDial = orange_table_has_column($pdo, 'orders', 'phone_country_dial');
    $hasOrdNat = orange_table_has_column($pdo, 'orders', 'phone_national');
    $cols = 'order_number, customer_name';
    $ph = '?, ?';
    $params = [
        $orderNumber,
        trim((string) $data['name']),
    ];
    if ($hasOrdDial) {
        $cols .= ', phone_country_dial';
        $ph .= ', ?';
        $params[] = $data['phone_country_dial'] ?? null;
    }
    if ($hasOrdNat) {
        $cols .= ', phone_national';
        $ph .= ', ?';
        $params[] = $data['phone_national'] ?? null;
    }
    $cols .= ', phone, area, address, notes, channel_id, status, total';
    $ph .= ', ?, ?, ?, ?, ?, ?, \'pending\', ?';
    array_push(
        $params,
        trim((string) $data['phone']),
        trim((string) $data['area']),
        trim((string) $data['address']),
        isset($data['notes']) ? trim((string) $data['notes']) : '',
        (int) $data['channel_id'],
        $orderTotal
    );
    if ($hasSource) {
        $cols .= ', order_source';
        $ph .= ', ?';
        $params[] = 'website';
    }
    if ($hasPay) {
        $cols .= ', payment_terms';
        $ph .= ', ?';
        $params[] = $paymentTerms;
    }
    if ($hasCustomerId && $customerRowId !== null && $customerRowId > 0) {
        $cols .= ', customer_id';
        $ph .= ', ?';
        $params[] = $customerRowId;
    }
    if ($hasSfa && $sfaLink !== null && $sfaLink > 0) {
        $cols .= ', storefront_account_id';
        $ph .= ', ?';
        $params[] = $sfaLink;
    }
    $hasDeliveryArea = orange_table_has_column($pdo, 'orders', 'delivery_area_id');
    if ($hasDeliveryArea) {
        $cols .= ', delivery_area_id';
        $ph .= ', ?';
        $daIns = isset($data['delivery_area_id']) ? (int) $data['delivery_area_id'] : 0;
        $params[] = $daIns > 0 ? $daIns : null;
    }
    $hasCartPromo = orange_table_has_column($pdo, 'orders', 'cart_promotion_id')
        && orange_table_has_column($pdo, 'orders', 'cart_promotion_discount');
    if ($hasCartPromo) {
        $cols .= ', cart_promotion_id, cart_promotion_discount';
        $ph .= ', ?, ?';
        $params[] = $promoId !== null && $promoId > 0 ? $promoId : null;
        $params[] = $promoDiscount > 0 ? $promoDiscount : 0.0;
    }
    $cols .= ', created_at';
    $ph .= ', NOW()';

    $orderStmt = $pdo->prepare("INSERT INTO orders ($cols) VALUES ($ph)");
    $orderStmt->execute($params);

    $orderId = (int) $pdo->lastInsertId();

    orange_storefront_insert_order_items_for_order($pdo, $orderId, $validatedItems);

    orange_order_apply_pending_stock_reservation($pdo, $orderNumber, $validatedItems);

    $messageLines = [];
    $messageLines[] = "Order Number: {$orderNumber}";
    $messageLines[] = 'Customer: ' . trim((string) $data['name']);
    $messageLines[] = 'Phone: ' . trim((string) $data['phone']);
    if (trim((string) $data['email']) !== '') {
        $messageLines[] = 'Email: ' . trim((string) $data['email']);
    }
    $messageLines[] = 'Area: ' . trim((string) $data['area']);
    $messageLines[] = 'Address: ' . trim((string) $data['address']);
    if (!empty($data['notes'])) {
        $messageLines[] = 'Notes: ' . trim((string) $data['notes']);
    }
    $messageLines[] = '';
    $messageLines[] = 'Items:';
    foreach ($validatedItems as $idx => $row) {
        $messageLines[] = ($idx + 1) . ') ' . $row['product']['name'];
        if ($row['color'] !== '') {
            $messageLines[] = '   Color: ' . $row['color'];
        }
        if ($row['size'] !== '') {
            $messageLines[] = '   Size: ' . $row['size'];
        }
        $messageLines[] = '   Qty: ' . $row['qty'];
        $messageLines[] = '   Price: ' . number_format($row['price'], 2) . ' KD';
    }
    $messageLines[] = '';
    $payAr = orange_order_payment_terms_label_ar($paymentTerms);
    $payEn = $paymentTerms === 'online' ? 'Online' : 'Cash';
    $messageLines[] = 'Payment: ' . $payEn . ' / ' . $payAr;
    $messageLines[] = 'Subtotal: ' . number_format($subtotal, 2) . ' KD';
    if ($promoDiscount > 0.00001) {
        $messageLines[] = 'Cart promotion: -' . number_format($promoDiscount, 2) . ' KD';
    }
    $messageLines[] = 'Total: ' . number_format($orderTotal, 2) . ' KD';

    $whatsAppNumber = clean_whatsapp_number((string) $channel['whatsapp_number']);
    $whatsAppUrl = 'https://wa.me/' . $whatsAppNumber . '?text=' . rawurlencode(implode("\n", $messageLines));

    return [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'total' => $orderTotal,
        'whatsapp_number' => $whatsAppNumber,
        'whatsapp_url' => $whatsAppUrl,
    ];
}

/**
 * Process the oldest pending intake job (FIFO). Uses one transaction with SAVEPOINT so order failure marks the queue row failed.
 */
function orange_order_intake_process_next(PDO $pdo): bool
{
    $pdo->beginTransaction();
    $qid = 0;
    try {
        $sel = $pdo->prepare(
            "SELECT id, payload_json FROM order_intake_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1 FOR UPDATE"
        );
        $sel->execute();
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $pdo->commit();

            return false;
        }
        $qid = (int) $row['id'];
        $payload = json_decode((string) $row['payload_json'], true);

        $pdo->exec('SAVEPOINT orange_intake_sp');
        try {
            if (!is_array($payload)) {
                throw new RuntimeException('Invalid queue payload');
            }
            $out = orange_storefront_execute_checkout_payload($pdo, $payload);
            $pdo->exec('RELEASE SAVEPOINT orange_intake_sp');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT orange_intake_sp');
            $errText = orange_order_intake_error_for_queue($e);
            $pdo->prepare(
                'UPDATE order_intake_queue SET status = ?, error_message = ?, attempts = attempts + 1 WHERE id = ?'
            )->execute(['failed', $errText, $qid]);
            if (function_exists('error_log')) {
                error_log('[orange] order_intake failed queue_id=' . $qid . ' err=' . $errText);
            }
            $pdo->commit();

            return true;
        }

        $pdo->prepare(
            'UPDATE order_intake_queue SET status = ?, order_id = ?, order_number = ?, whatsapp_url = ?, whatsapp_number = ? WHERE id = ?'
        )->execute([
            'completed',
            $out['order_id'],
            $out['order_number'],
            $out['whatsapp_url'],
            $out['whatsapp_number'],
            $qid,
        ]);
        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<string,mixed> $data
 * @return array{id:int, public_token:string}
 */
function orange_order_intake_enqueue(PDO $pdo, array $data): array
{
    $token = bin2hex(random_bytes(16));
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($data, $flags);
    if ($json === false) {
        throw new RuntimeException('Could not encode checkout payload');
    }
    $ins = $pdo->prepare(
        "INSERT INTO order_intake_queue (public_token, status, payload_json) VALUES (?, 'pending', ?)"
    );
    $ins->execute([$token, $json]);

    return ['id' => (int) $pdo->lastInsertId(), 'public_token' => $token];
}
