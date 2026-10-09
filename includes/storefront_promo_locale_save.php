<?php
declare(strict_types=1);

/**
 * Promo message property save plus locale text in one transaction.
 * Property update does not blank the legacy text columns.
 * The locale helper reads those original columns and writes only intended changes.
 * Does not load config.php.
 */

require_once __DIR__ . '/orange_content_locale_field.php';

/**
 * @param array{
 *   slot:string,
 *   audience:string,
 *   offer_type:?string,
 *   offer_id:?int,
 *   is_active:int,
 *   is_always_on:int,
 *   valid_from:?string,
 *   valid_to:?string
 * } $props
 * @param array<string, array<string, mixed>> $locales
 */
function orange_storefront_promo_locale_save(PDO $pdo, int $countryId, int $id, array $props, string $baseText, array $locales): int
{
    if ($countryId <= 0) {
        throw new InvalidArgumentException('country_required');
    }
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $chk = $pdo->prepare('SELECT country_id FROM storefront_promo_messages WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if (!is_array($existing)) {
                throw new RuntimeException('entity_missing');
            }
            $exCid = (int) ($existing['country_id'] ?? 0);
            if ($exCid > 0 && $exCid !== $countryId) {
                throw new RuntimeException('country_mismatch');
            }
            $match = $exCid > 0
                ? 'id = ? AND country_id = ?'
                : 'id = ? AND (country_id IS NULL OR country_id = 0)';
            $sql = 'UPDATE storefront_promo_messages
                SET country_id = ?, slot = ?, audience = ?, offer_type = ?, offer_id = ?,
                    is_active = ?, is_always_on = ?, valid_from = ?, valid_to = ?
                WHERE ' . $match;
            $st = $pdo->prepare($sql);
            $params = [
                $countryId,
                $props['slot'],
                $props['audience'],
                $props['offer_type'],
                $props['offer_id'],
                $props['is_active'],
                $props['is_always_on'],
                $props['valid_from'],
                $props['valid_to'],
            ];
            if ($exCid > 0) {
                $params[] = $id;
                $params[] = $exCid;
            } else {
                $params[] = $id;
            }
            $st->execute($params);
        } else {
            $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM storefront_promo_messages WHERE country_id = ?');
            $sortStmt->execute([$countryId]);
            $sortOrder = (int) $sortStmt->fetchColumn();
            if ($sortOrder < 1) {
                $sortOrder = 1;
            }
            $st = $pdo->prepare(
                'INSERT INTO storefront_promo_messages
                    (country_id, slot, audience, offer_type, offer_id, text_ar, text_en, text_fil, text_hi, is_active, is_always_on, valid_from, valid_to, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $countryId,
                $props['slot'],
                $props['audience'],
                $props['offer_type'],
                $props['offer_id'],
                '',
                '',
                '',
                '',
                $props['is_active'],
                $props['is_always_on'],
                $props['valid_from'],
                $props['valid_to'],
                $sortOrder,
            ]);
            $id = (int) $pdo->lastInsertId();
        }
        orange_content_locale_field_save(
            $pdo,
            $countryId,
            'storefront_promo_messages',
            'promo_message',
            $id,
            $baseText,
            $locales,
            false
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $id;
}
