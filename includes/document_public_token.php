<?php

declare(strict_types=1);

/**
 * رابط مستند الفاتورة/المردود العام عبر QR (س27 — استثناء معتمد 2026-06-12).
 *
 * توكن عشوائي غير قابل للتخمين لكل (doc_kind, doc_id) — لا يكشف رقم الطلب/الهاتف.
 * يُستخدم لفتح صفحة عرض عامة `pages/document.php` يختار فيها العميل اللغة.
 */

require_once __DIR__ . '/../config.php';

/** أنواع المستندات المسموح بها (الشاشات الخمس). */
function orange_doc_public_token_kinds(): array
{
    return [
        'inv_c',           // فاتورة مبيعات الشركة (orders)
        'inv_o',           // فاتورة مبيعات أونلاين (orders)
        'sales_return',    // مردود مبيعات (sales_returns)
        'purchase',        // فاتورة مشتريات (purchases)
        'purchase_return', // مردود مشتريات (purchase_returns)
    ];
}

function orange_doc_public_token_kind_valid(string $docKind): bool
{
    return in_array($docKind, orange_doc_public_token_kinds(), true);
}

/**
 * يُرجع توكن المستند الحالي إن وُجد (غير مُبطَل وغير منتهٍ)، أو null.
 */
function orange_doc_public_token_for_doc(PDO $pdo, string $docKind, int $docId): ?string
{
    if (! orange_doc_public_token_kind_valid($docKind) || $docId <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT token, revoked, expires_at FROM document_public_tokens
             WHERE doc_kind = ? AND doc_id = ? LIMIT 1'
        );
        $st->execute([$docKind, $docId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (! $row) {
            return null;
        }
        if ((int) ($row['revoked'] ?? 0) === 1) {
            return null;
        }
        $exp = $row['expires_at'] ?? null;
        if ($exp !== null && $exp !== '' && strtotime((string) $exp) !== false && strtotime((string) $exp) < time()) {
            return null;
        }

        return (string) $row['token'];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * يضمن وجود توكن للمستند (ينشئه إن لزم) ويعيده. null عند الفشل.
 */
function orange_doc_public_token_ensure(PDO $pdo, string $docKind, int $docId, ?int $countryId = null): ?string
{
    if (! orange_doc_public_token_kind_valid($docKind) || $docId <= 0) {
        return null;
    }
    $existing = orange_doc_public_token_for_doc($pdo, $docKind, $docId);
    if ($existing !== null) {
        return $existing;
    }
    try {
        $token = bin2hex(random_bytes(20)); // 40 hex chars = 160-bit
        $ins = $pdo->prepare(
            'INSERT INTO document_public_tokens (token, doc_kind, doc_id, country_id)
             VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$token, $docKind, $docId, $countryId !== null && $countryId > 0 ? $countryId : null]);

        return $token;
    } catch (Throwable $e) {
        // سباق محتمل على uq_doc_public_doc — أعد القراءة.
        $again = orange_doc_public_token_for_doc($pdo, $docKind, $docId);

        return $again;
    }
}

/**
 * يبحث عن المستند بواسطة التوكن. يعيد ['doc_kind','doc_id','country_id'] أو null.
 */
function orange_doc_public_token_lookup(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if (! preg_match('/^[a-f0-9]{40}$/', $token)) {
        return null;
    }
    try {
        $st = $pdo->prepare(
            'SELECT doc_kind, doc_id, country_id, revoked, expires_at
             FROM document_public_tokens WHERE token = ? LIMIT 1'
        );
        $st->execute([$token]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (! $row) {
            return null;
        }
        if ((int) ($row['revoked'] ?? 0) === 1) {
            return null;
        }
        $exp = $row['expires_at'] ?? null;
        if ($exp !== null && $exp !== '' && strtotime((string) $exp) !== false && strtotime((string) $exp) < time()) {
            return null;
        }
        if (! orange_doc_public_token_kind_valid((string) $row['doc_kind'])) {
            return null;
        }

        return [
            'doc_kind' => (string) $row['doc_kind'],
            'doc_id' => (int) $row['doc_id'],
            'country_id' => isset($row['country_id']) ? (int) $row['country_id'] : 0,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** المسار النسبي لصفحة العرض العامة. */
function orange_doc_public_relative_url(string $token, ?string $lang = null): string
{
    $q = ['t' => $token];
    if ($lang !== null && $lang !== '') {
        $q['lang'] = $lang;
    }

    return storefront_public_path('/pages/document.php') . '?' . http_build_query($q);
}

/** الرابط المطلق (للـ QR المطبوع). */
function orange_doc_public_absolute_url(string $token, ?string $lang = null): string
{
    return orange_site_public_origin() . orange_doc_public_relative_url($token, $lang);
}
