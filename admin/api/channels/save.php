<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_admin_api();

function channels_ensure_warehouse_column(PDO $pdo): void
{
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM channels LIKE 'warehouse_number'");
        if ($chk && !$chk->fetch()) {
            $pdo->exec('ALTER TABLE channels ADD COLUMN warehouse_number TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER whatsapp_number');
        }
    } catch (Throwable $e) {
    }
}

/**
 * Slug فريد لجدول channels (للـ ?channel= والكوكي). عند التعديل: استثناء صف بالمعرّف الحالي.
 */
function channels_next_unique_slug(PDO $pdo, string $base, ?int $exceptChannelId = null, int $countryId = 0): string
{
    $b = strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', $base));
    if ($b === '') {
        $b = 'channel';
    }
    $hasCountry = $countryId > 0 && orange_channels_has_country_column($pdo);
    for ($i = 0; $i < 500; $i++) {
        $try = $i === 0 ? $b : $b . '-' . $i;
        if ($exceptChannelId !== null) {
            if ($hasCountry) {
                $st = $pdo->prepare('SELECT id FROM channels WHERE slug = ? AND country_id = ? AND id <> ? LIMIT 1');
                $st->execute([$try, $countryId, $exceptChannelId]);
            } else {
                $st = $pdo->prepare('SELECT id FROM channels WHERE slug = ? AND id <> ? LIMIT 1');
                $st->execute([$try, $exceptChannelId]);
            }
        } elseif ($hasCountry) {
            $st = $pdo->prepare('SELECT id FROM channels WHERE slug = ? AND country_id = ? LIMIT 1');
            $st->execute([$try, $countryId]);
        } else {
            $st = $pdo->prepare('SELECT id FROM channels WHERE slug = ? LIMIT 1');
            $st->execute([$try]);
        }
        if (!$st->fetch()) {
            return $try;
        }
    }

    return $b . '-' . bin2hex(random_bytes(3));
}

function channels_sync_storefront_accounts_slug(PDO $pdo, string $oldSlug, string $newSlug): void
{
    if ($oldSlug === $newSlug || $oldSlug === '' || $newSlug === '') {
        return;
    }
    if (!orange_table_exists($pdo, 'storefront_accounts')) {
        return;
    }
    if (!orange_table_has_column($pdo, 'storefront_accounts', 'registered_channel_slug')) {
        return;
    }
    try {
        $st = $pdo->prepare(
            'UPDATE storefront_accounts SET registered_channel_slug = ? WHERE registered_channel_slug = ?'
        );
        $st->execute([$newSlug, $oldSlug]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] channels_sync_storefront_accounts_slug: ' . $e->getMessage());
        }
    }
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    channels_ensure_warehouse_column($pdo);

    $data = get_json_input();
    $id = isset($data['id']) ? (int) $data['id'] : 0;

    if (empty($data['name']) || empty($data['path_segment']) || empty($data['whatsapp_number'])) {
        json_response(['success' => false, 'message' => 'الاسم واختصار الرابط ورقم الواتساب مطلوبة'], 422);
    }

    $pathSeg = strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', (string) $data['path_segment']));
    if ($pathSeg === '' || strlen($pathSeg) > 64) {
        json_response(['success' => false, 'message' => 'اختصار الرابط غير صالح (حروف إنجليزية وأرقام وشرطة فقط)'], 422);
    }
    if (in_array($pathSeg, orange_storefront_reserved_path_segments(), true)) {
        json_response(['success' => false, 'message' => 'هذا الاختصار محجوز لمسارات النظام — اختر اسماً آخر'], 422);
    }

    $logo = trim((string) ($data['logo'] ?? ''));
    $name = trim((string) $data['name']);
    $wa = trim((string) $data['whatsapp_number']);
    $wh = 1;
    $countryId = (int) ($data['country_id'] ?? 0);
    $ctxCountry = orange_admin_context_country_id($pdo);
    if ($ctxCountry > 0) {
        $countryId = $ctxCountry;
    } elseif ($countryId <= 0) {
        $countryId = orange_countries_default_id($pdo);
    }
    if ($countryId <= 0) {
        json_response(['success' => false, 'message' => 'الدولة مطلوبة'], 422);
    }
    $channelKind = orange_channel_kind_normalize((string) ($data['channel_kind'] ?? 'other'));
    $hasCountryCol = orange_channels_has_country_column($pdo);
    $hasKindCol = orange_table_has_column($pdo, 'channels', 'channel_kind');

    $rawAct = $data['is_active'] ?? null;
    $isActive = 1;
    if ($rawAct !== null) {
        $isActive = ((int) $rawAct === 1 || $rawAct === true || $rawAct === '1') ? 1 : 0;
    }
    $hasDefaultCol = orange_table_has_column($pdo, 'channels', 'is_country_default');
    $rawDefault = $data['is_country_default'] ?? null;
    $isCountryDefault = 0;
    if ($hasDefaultCol && $rawDefault !== null) {
        $isCountryDefault = ((int) $rawDefault === 1 || $rawDefault === true || $rawDefault === '1') ? 1 : 0;
    }
    if ($hasDefaultCol && $isCountryDefault === 1 && $isActive !== 1) {
        json_response(['success' => false, 'message' => 'القناة الرئيسية يجب أن تكون نشطة'], 422);
    }

    if ($id > 0) {
        try {
            orange_admin_assert_row_country($pdo, 'channels', $id);
        } catch (RuntimeException $e) {
            json_response(['success' => false, 'message' => $e->getMessage()], 403);
        }
        $load = $pdo->prepare('SELECT id, slug, path_segment FROM channels WHERE id = ? LIMIT 1');
        $load->execute([$id]);
        $row = $load->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            json_response(['success' => false, 'message' => 'القناة غير موجودة'], 404);
        }
        $oldSlug = (string) ($row['slug'] ?? '');
        $oldPath = (string) ($row['path_segment'] ?? '');

        if ($hasCountryCol) {
            $dupPath = $pdo->prepare('SELECT id FROM channels WHERE path_segment = ? AND country_id = ? AND id <> ? LIMIT 1');
            $dupPath->execute([$pathSeg, $countryId, $id]);
        } else {
            $dupPath = $pdo->prepare('SELECT id FROM channels WHERE path_segment = ? AND id <> ? LIMIT 1');
            $dupPath->execute([$pathSeg, $id]);
        }
        if ($dupPath->fetch()) {
            json_response(['success' => false, 'message' => 'اختصار الرابط مستخدم بالفعل لهذه الدولة'], 409);
        }

        $newSlug = $oldSlug;
        if ($pathSeg !== $oldPath) {
            $newSlug = channels_next_unique_slug($pdo, $pathSeg, $id, $countryId);
        }

        if ($hasCountryCol && $hasKindCol) {
            $upd = $pdo->prepare(
                'UPDATE channels SET name = ?, slug = ?, path_segment = ?, logo = ?, whatsapp_number = ?, warehouse_number = ?, is_active = ?, country_id = ?, channel_kind = ?'
                . ($hasDefaultCol ? ', is_country_default = ?' : '') . ' WHERE id = ?'
            );
            $params = [$name, $newSlug, $pathSeg, $logo, $wa, $wh, $isActive, $countryId, $channelKind];
            if ($hasDefaultCol) {
                $params[] = $isCountryDefault;
            }
            $params[] = $id;
            $upd->execute($params);
        } elseif ($hasCountryCol) {
            $upd = $pdo->prepare(
                'UPDATE channels SET name = ?, slug = ?, path_segment = ?, logo = ?, whatsapp_number = ?, warehouse_number = ?, is_active = ?, country_id = ?'
                . ($hasDefaultCol ? ', is_country_default = ?' : '') . ' WHERE id = ?'
            );
            $params = [$name, $newSlug, $pathSeg, $logo, $wa, $wh, $isActive, $countryId];
            if ($hasDefaultCol) {
                $params[] = $isCountryDefault;
            }
            $params[] = $id;
            $upd->execute($params);
        } else {
            $upd = $pdo->prepare(
                'UPDATE channels SET name = ?, slug = ?, path_segment = ?, logo = ?, whatsapp_number = ?, warehouse_number = ?, is_active = ?'
                . ($hasDefaultCol ? ', is_country_default = ?' : '') . ' WHERE id = ?'
            );
            $params = [$name, $newSlug, $pathSeg, $logo, $wa, $wh, $isActive];
            if ($hasDefaultCol) {
                $params[] = $isCountryDefault;
            }
            $params[] = $id;
            $upd->execute($params);
        }

        if ($hasDefaultCol && $isCountryDefault === 1) {
            $pdo->prepare(
                'UPDATE channels SET is_country_default = 0 WHERE country_id = ? AND id <> ?'
            )->execute([$countryId, $id]);
        }

        if ($newSlug !== $oldSlug) {
            channels_sync_storefront_accounts_slug($pdo, $oldSlug, $newSlug);
        }

        json_response([
            'success' => true,
            'message' => 'تم تحديث الواجهة',
            'slug' => $newSlug,
            'path_segment' => $pathSeg,
        ]);
    }

    if ($hasCountryCol) {
        $dupPath = $pdo->prepare('SELECT 1 FROM channels WHERE path_segment = ? AND country_id = ? LIMIT 1');
        $dupPath->execute([$pathSeg, $countryId]);
    } else {
        $dupPath = $pdo->prepare('SELECT 1 FROM channels WHERE path_segment = ? LIMIT 1');
        $dupPath->execute([$pathSeg]);
    }
    if ($dupPath->fetch()) {
        json_response(['success' => false, 'message' => 'اختصار الرابط مستخدم بالفعل لهذه الدولة'], 409);
    }

    $slug = channels_next_unique_slug($pdo, $pathSeg, null, $countryId);

    if ($hasCountryCol && $hasKindCol) {
        $cols = 'name, slug, path_segment, logo, whatsapp_number, warehouse_number, is_active, country_id, channel_kind';
        $vals = '?, ?, ?, ?, ?, ?, ?, ?, ?';
        $params = [$name, $slug, $pathSeg, $logo, $wa, $wh, $isActive, $countryId, $channelKind];
        if ($hasDefaultCol) {
            $cols .= ', is_country_default';
            $vals .= ', ?';
            $params[] = $isCountryDefault;
        }
        $stmt = $pdo->prepare('INSERT INTO channels (' . $cols . ') VALUES (' . $vals . ')');
        $stmt->execute($params);
    } elseif ($hasCountryCol) {
        $cols = 'name, slug, path_segment, logo, whatsapp_number, warehouse_number, is_active, country_id';
        $vals = '?, ?, ?, ?, ?, ?, ?, ?';
        $params = [$name, $slug, $pathSeg, $logo, $wa, $wh, $isActive, $countryId];
        if ($hasDefaultCol) {
            $cols .= ', is_country_default';
            $vals .= ', ?';
            $params[] = $isCountryDefault;
        }
        $stmt = $pdo->prepare('INSERT INTO channels (' . $cols . ') VALUES (' . $vals . ')');
        $stmt->execute($params);
    } else {
        $cols = 'name, slug, path_segment, logo, whatsapp_number, warehouse_number, is_active';
        $vals = '?, ?, ?, ?, ?, ?, ?';
        $params = [$name, $slug, $pathSeg, $logo, $wa, $wh, $isActive];
        if ($hasDefaultCol) {
            $cols .= ', is_country_default';
            $vals .= ', ?';
            $params[] = $isCountryDefault;
        }
        $stmt = $pdo->prepare('INSERT INTO channels (' . $cols . ') VALUES (' . $vals . ')');
        $stmt->execute($params);
    }

    if ($hasDefaultCol && $isCountryDefault === 1) {
        $newId = (int) $pdo->lastInsertId();
        if ($newId > 0) {
            $pdo->prepare(
                'UPDATE channels SET is_country_default = 0 WHERE country_id = ? AND id <> ?'
            )->execute([$countryId, $newId]);
        }
    }

    json_response(['success' => true, 'message' => 'تم حفظ الواجهة', 'slug' => $slug, 'path_segment' => $pathSeg]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ القناة');
}
