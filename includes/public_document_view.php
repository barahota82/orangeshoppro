<?php

declare(strict_types=1);

/**
 * تحميل مستند فاتورة/مردود لعرضه على صفحة عامة (QR) بأي لغة.
 * يُعيد بنية موحّدة لكل الأنواع الخمسة. الأسماء تُترجَم حسب current_lang().
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/countries.php';

/** أسماء المنتجات مُترجَمة حسب اللغة الحالية. [product_id => name] */
function orange_public_doc_localized_names(PDO $pdo, array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn ($v): bool => $v > 0)));
    if ($ids === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    try {
        $st = $pdo->prepare("SELECT id, name, name_en, name_fil, name_hi FROM products WHERE id IN ($place)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = storefront_product_display_name($row);
        }
    } catch (Throwable $e) {
        // أعمدة اللغة قد لا تكون كلها موجودة — fallback للاسم الأساسي.
        try {
            $st = $pdo->prepare("SELECT id, name FROM products WHERE id IN ($place)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int) $row['id']] = (string) $row['name'];
            }
        } catch (Throwable $e2) {
            // ignore
        }
    }

    return $out;
}

/** ألوان/مقاسات المتغيّرات. [variant_id => "color / size"] */
function orange_public_doc_variant_labels(PDO $pdo, array $variantIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $variantIds), static fn ($v): bool => $v > 0)));
    if ($ids === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    try {
        $st = $pdo->prepare("SELECT id, color, size FROM product_variants WHERE id IN ($place)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $c = trim((string) ($row['color'] ?? ''));
            $z = trim((string) ($row['size'] ?? ''));
            $lbl = ($c !== '' && $z !== '') ? ($c . ' / ' . $z) : ($c !== '' ? $c : $z);
            if ($lbl !== '') {
                $out[(int) $row['id']] = $lbl;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $out;
}

function orange_public_doc_fmt_date(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '';
    }
    $ts = strtotime($raw);

    return $ts !== false ? date('Y-m-d', $ts) : (string) $raw;
}

/**
 * @return array<string,mixed>|null
 */
function orange_public_document_load(PDO $pdo, string $docKind, int $docId, int $countryId = 0): ?array
{
    if ($docId <= 0) {
        return null;
    }
    switch ($docKind) {
        case 'inv_c':
        case 'inv_o':
            return orange_public_doc_load_order($pdo, $docKind, $docId);
        case 'sales_return':
            return orange_public_doc_load_sales_return($pdo, $docId);
        case 'purchase':
            return orange_public_doc_load_purchase($pdo, $docId, $countryId);
        case 'purchase_return':
            return orange_public_doc_load_purchase_return($pdo, $docId);
        default:
            return null;
    }
}

function orange_public_doc_load_order(PDO $pdo, string $docKind, int $orderId): ?array
{
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (! $o) {
        return null;
    }
    $its = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $its->execute([$orderId]);
    $rows = $its->fetchAll(PDO::FETCH_ASSOC);

    $names = orange_public_doc_localized_names($pdo, array_column($rows, 'product_id'));
    $lines = [];
    $subtotal = 0.0;
    $discTotal = 0.0;
    foreach ($rows as $r) {
        $qty = (float) ($r['qty'] ?? 0);
        $price = (float) ($r['price'] ?? 0);
        $disc = (float) ($r['line_discount'] ?? 0);
        $gross = $qty * $price;
        $net = max(0.0, $gross - $disc);
        $pid = (int) ($r['product_id'] ?? 0);
        $variant = trim((string) ($r['color'] ?? '') . (($r['color'] ?? '') !== '' && ($r['size'] ?? '') !== '' ? ' / ' : '') . (string) ($r['size'] ?? ''));
        $lines[] = [
            'name' => $names[$pid] ?? (string) ($r['product_name'] ?? ''),
            'variant' => $variant,
            'qty' => $qty,
            'price' => $price,
            'discount' => $disc,
            'total' => $net,
        ];
        $subtotal += $gross;
        $discTotal += $disc;
    }

    return [
        'doc_kind' => $docKind,
        'serial' => (string) ($o['invoice_number'] ?? ''),
        'date' => orange_public_doc_fmt_date($o['document_date'] ?? ($o['created_at'] ?? null)),
        'party_kind' => 'customer',
        'party_name' => (string) ($o['customer_name'] ?? ''),
        'party_phone' => (string) ($o['phone'] ?? ''),
        'party_area' => (string) ($o['area'] ?? ''),
        'party_address' => (string) ($o['address'] ?? ''),
        'currency_code' => (string) ($o['currency_code'] ?? ''),
        'country_id' => (int) ($o['country_id'] ?? 0),
        'lines' => $lines,
        'subtotal' => round($subtotal, 3),
        'discount_total' => round($discTotal, 3),
        'net_total' => round((float) ($o['total'] ?? ($subtotal - $discTotal)), 3),
    ];
}

function orange_public_doc_load_sales_return(PDO $pdo, int $returnId): ?array
{
    $st = $pdo->prepare('SELECT * FROM sales_returns WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (! $h) {
        return null;
    }
    $its = $pdo->prepare('SELECT * FROM sales_return_items WHERE sales_return_id = ? ORDER BY id ASC');
    $its->execute([$returnId]);
    $rows = $its->fetchAll(PDO::FETCH_ASSOC);

    $names = orange_public_doc_localized_names($pdo, array_column($rows, 'product_id'));
    $vlabels = orange_public_doc_variant_labels($pdo, array_column($rows, 'variant_id'));
    $lines = [];
    $subtotal = 0.0;
    $discTotal = 0.0;
    foreach ($rows as $r) {
        $qty = (float) ($r['qty'] ?? 0);
        $price = (float) ($r['price'] ?? 0);
        $disc = (float) ($r['line_discount'] ?? 0);
        $gross = $qty * $price;
        $net = max(0.0, $gross - $disc);
        $lines[] = [
            'name' => $names[(int) ($r['product_id'] ?? 0)] ?? '',
            'variant' => $vlabels[(int) ($r['variant_id'] ?? 0)] ?? '',
            'qty' => $qty,
            'price' => $price,
            'discount' => $disc,
            'total' => $net,
        ];
        $subtotal += $gross;
        $discTotal += $disc;
    }

    $partyName = '';
    $partyPhone = '';
    $custId = (int) ($h['customer_id'] ?? 0);
    if ($custId > 0) {
        try {
            $cs = $pdo->prepare('SELECT name_ar, phone FROM customers WHERE id = ? LIMIT 1');
            $cs->execute([$custId]);
            $c = $cs->fetch(PDO::FETCH_ASSOC);
            if ($c) {
                $partyName = (string) ($c['name_ar'] ?? '');
                $partyPhone = (string) ($c['phone'] ?? '');
            }
        } catch (Throwable $e) {
        }
    }

    return [
        'doc_kind' => 'sales_return',
        'serial' => (string) ($h['return_number'] ?? ''),
        'date' => orange_public_doc_fmt_date($h['document_date'] ?? ($h['created_at'] ?? null)),
        'party_kind' => 'customer',
        'party_name' => $partyName,
        'party_phone' => $partyPhone,
        'party_area' => '',
        'party_address' => '',
        'currency_code' => (string) ($h['currency_code'] ?? ''),
        'country_id' => (int) ($h['country_id'] ?? 0),
        'lines' => $lines,
        'subtotal' => round($subtotal, 3),
        'discount_total' => round($discTotal, 3),
        'net_total' => round((float) ($h['total'] ?? ($subtotal - $discTotal)), 3),
    ];
}

function orange_public_doc_load_purchase(PDO $pdo, int $purchaseId, int $countryId): ?array
{
    $st = $pdo->prepare('SELECT * FROM purchases WHERE id = ? LIMIT 1');
    $st->execute([$purchaseId]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (! $h) {
        return null;
    }
    $its = $pdo->prepare('SELECT * FROM purchase_items WHERE purchase_id = ? ORDER BY id ASC');
    $its->execute([$purchaseId]);
    $rows = $its->fetchAll(PDO::FETCH_ASSOC);

    $names = orange_public_doc_localized_names($pdo, array_column($rows, 'product_id'));
    $vlabels = orange_public_doc_variant_labels($pdo, array_column($rows, 'variant_id'));
    $lines = [];
    $subtotal = 0.0;
    $discTotal = 0.0;
    foreach ($rows as $r) {
        $qty = (float) ($r['qty'] ?? 0);
        $price = (float) ($r['cost'] ?? 0);
        $disc = (float) ($r['discount_amount'] ?? 0);
        $gross = $qty * $price;
        $net = max(0.0, $gross - $disc);
        $lines[] = [
            'name' => $names[(int) ($r['product_id'] ?? 0)] ?? '',
            'variant' => $vlabels[(int) ($r['variant_id'] ?? 0)] ?? '',
            'qty' => $qty,
            'price' => $price,
            'discount' => $disc,
            'total' => $net,
        ];
        $subtotal += $gross;
        $discTotal += $disc;
    }

    $cId = $countryId > 0 ? $countryId : (int) ($h['country_id'] ?? 0);
    $serial = orange_country_document_ref($pdo, 'PUR', $purchaseId, $cId);

    $partyName = '';
    $partyPhone = '';
    $supId = (int) ($h['supplier_id'] ?? 0);
    if ($supId > 0) {
        try {
            $ss = $pdo->prepare('SELECT name, phone FROM suppliers WHERE id = ? LIMIT 1');
            $ss->execute([$supId]);
            $s = $ss->fetch(PDO::FETCH_ASSOC);
            if ($s) {
                $partyName = (string) ($s['name'] ?? '');
                $partyPhone = (string) ($s['phone'] ?? '');
            }
        } catch (Throwable $e) {
        }
    }

    return [
        'doc_kind' => 'purchase',
        'serial' => $serial,
        'date' => orange_public_doc_fmt_date($h['document_date'] ?? ($h['created_at'] ?? null)),
        'party_kind' => 'supplier',
        'party_name' => $partyName,
        'party_phone' => $partyPhone,
        'party_area' => '',
        'party_address' => '',
        'currency_code' => (string) ($h['currency_code'] ?? ''),
        'country_id' => $cId,
        'lines' => $lines,
        'subtotal' => round($subtotal, 3),
        'discount_total' => round($discTotal, 3),
        'net_total' => round((float) ($h['total'] ?? ($subtotal - $discTotal)), 3),
    ];
}

function orange_public_doc_load_purchase_return(PDO $pdo, int $returnId): ?array
{
    $st = $pdo->prepare('SELECT * FROM purchase_returns WHERE id = ? LIMIT 1');
    $st->execute([$returnId]);
    $h = $st->fetch(PDO::FETCH_ASSOC);
    if (! $h) {
        return null;
    }
    $its = $pdo->prepare('SELECT * FROM purchase_return_items WHERE purchase_return_id = ? ORDER BY id ASC');
    $its->execute([$returnId]);
    $rows = $its->fetchAll(PDO::FETCH_ASSOC);

    $names = orange_public_doc_localized_names($pdo, array_column($rows, 'product_id'));
    $vlabels = orange_public_doc_variant_labels($pdo, array_column($rows, 'variant_id'));
    $lines = [];
    $subtotal = 0.0;
    $discTotal = 0.0;
    foreach ($rows as $r) {
        $qty = (float) ($r['qty'] ?? 0);
        $price = (float) ($r['cost'] ?? 0);
        $disc = (float) ($r['discount_amount'] ?? 0);
        $gross = $qty * $price;
        $net = max(0.0, $gross - $disc);
        $lines[] = [
            'name' => $names[(int) ($r['product_id'] ?? 0)] ?? '',
            'variant' => $vlabels[(int) ($r['variant_id'] ?? 0)] ?? '',
            'qty' => $qty,
            'price' => $price,
            'discount' => $disc,
            'total' => $net,
        ];
        $subtotal += $gross;
        $discTotal += $disc;
    }

    $partyName = '';
    $partyPhone = '';
    $supId = (int) ($h['supplier_id'] ?? 0);
    if ($supId > 0) {
        try {
            $ss = $pdo->prepare('SELECT name, phone FROM suppliers WHERE id = ? LIMIT 1');
            $ss->execute([$supId]);
            $s = $ss->fetch(PDO::FETCH_ASSOC);
            if ($s) {
                $partyName = (string) ($s['name'] ?? '');
                $partyPhone = (string) ($s['phone'] ?? '');
            }
        } catch (Throwable $e) {
        }
    }

    return [
        'doc_kind' => 'purchase_return',
        'serial' => (string) ($h['return_number'] ?? ''),
        'date' => orange_public_doc_fmt_date($h['document_date'] ?? ($h['created_at'] ?? null)),
        'party_kind' => 'supplier',
        'party_name' => $partyName,
        'party_phone' => $partyPhone,
        'party_area' => '',
        'party_address' => '',
        'currency_code' => (string) ($h['currency_code'] ?? ''),
        'country_id' => (int) ($h['country_id'] ?? 0),
        'lines' => $lines,
        'subtotal' => round($subtotal, 3),
        'discount_total' => round($discTotal, 3),
        'net_total' => round((float) ($h['total'] ?? ($subtotal - $discTotal)), 3),
    ];
}
