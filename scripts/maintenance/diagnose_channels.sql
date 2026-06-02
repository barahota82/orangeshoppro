-- Orange — تشخيص جدول channels (تكرار / مسارات الزوار)
-- نفّذ في phpMyAdmin على قاعدة Orange.

-- 1) تكرار داخل نفس الدولة + نفس اختصار الرابط (هذا يكسر /tiktok وغيره)
SELECT country_id, path_segment, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS channel_ids
FROM channels
WHERE path_segment IS NOT NULL AND path_segment <> ''
GROUP BY country_id, path_segment
HAVING cnt > 1;

-- 2) تكرار slug داخل نفس الدولة
SELECT country_id, slug, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS channel_ids
FROM channels
WHERE slug IS NOT NULL AND slug <> ''
GROUP BY country_id, slug
HAVING cnt > 1;

-- 3) كل القنوات (مرتبة)
SELECT id, country_id, name, slug, path_segment, is_active, is_country_default, channel_kind
FROM channels
ORDER BY country_id, path_segment, id;

-- 4) قنوات نشطة لكل دولة (ما يستخدمه الزوار)
SELECT c.id, c.country_id, co.code AS country_code, c.name, c.slug, c.path_segment, c.is_active
FROM channels c
LEFT JOIN countries co ON co.id = c.country_id
WHERE c.is_active = 1
ORDER BY c.country_id, c.path_segment;

-- 5) بعد حذف المكرر: git pull ثم افتح الأدمن أو
--    php scripts/run_db_id_renumber_phases.php
--    → يسجّل php_db_id_renumber_channels_v88 ويضبط id من 1 بلا فراغات
