<?php
declare(strict_types=1);

/**
 * Home-hero active flag and locale text in one transaction.
 * The update does not rewrite untouched text columns.
 * Does not load config.php.
 */

require_once __DIR__ . '/orange_content_locale_field.php';

/**
 * @param array<string, array<string, mixed>> $locales
 */
function orange_storefront_copy_locale_save(PDO $pdo, int $countryId, int $id, int $isActive, string $baseText, array $locales, bool $scoped): int
{
    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            if ($scoped) {
                $chk = $pdo->prepare('SELECT id FROM storefront_copy_lines WHERE id = ? AND country_id = ? AND scope = ? LIMIT 1');
                $chk->execute([$id, $countryId, 'home_hero']);
            } else {
                $chk = $pdo->prepare('SELECT id FROM storefront_copy_lines WHERE id = ? AND scope = ? LIMIT 1');
                $chk->execute([$id, 'home_hero']);
            }
            if (!is_array($chk->fetch(PDO::FETCH_ASSOC))) {
                throw new RuntimeException('entity_missing');
            }
            if ($scoped) {
                $active = $pdo->prepare('UPDATE storefront_copy_lines SET is_active = ? WHERE id = ? AND country_id = ? AND scope = ?');
                $active->execute([$isActive, $id, $countryId, 'home_hero']);
            } else {
                $active = $pdo->prepare('UPDATE storefront_copy_lines SET is_active = ? WHERE id = ? AND scope = ?');
                $active->execute([$isActive, $id, 'home_hero']);
            }
        } else {
            if ($scoped && $countryId > 0) {
                $sortSt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM storefront_copy_lines WHERE country_id = ? AND scope = ?');
                $sortSt->execute([$countryId, 'home_hero']);
            } else {
                $sortSt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM storefront_copy_lines WHERE scope = ?');
                $sortSt->execute(['home_hero']);
            }
            $sort = (int) $sortSt->fetchColumn() + 1;
            if ($scoped) {
                $ins = $pdo->prepare('INSERT INTO storefront_copy_lines (country_id, scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $ins->execute([$countryId, 'home_hero', $sort, $isActive, '', '', '', '']);
            } else {
                $ins = $pdo->prepare('INSERT INTO storefront_copy_lines (scope, sort_order, is_active, text_ar, text_en, text_fil, text_hi) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $ins->execute(['home_hero', $sort, $isActive, '', '', '', '']);
            }
            $id = (int) $pdo->lastInsertId();
        }
        orange_content_locale_field_save($pdo, $countryId, 'storefront_copy_lines', 'copy_line', $id, $baseText, $locales, false);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $id;
}
