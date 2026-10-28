<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
return file_get_contents(__FILE__, false, null, __COMPILER_HALT_OFFSET__);
__halt_compiler();-- READ ONLY. Run only after a later authorized delete, before copying packs.
-- Expect no rows. KEEP fingerprints, roles, brand, and empty content must still match the hosted baseline.
SELECT 'database' AS check_id, IFNULL(DATABASE(), 'NULL') AS found
WHERE DATABASE() IS NULL OR DATABASE() <> 'orangetest'
UNION ALL
SELECT 'reference_count', CAST(COUNT(*) AS CHAR) FROM `orangetest`.`orange_language_reference`
HAVING COUNT(*) <> 41
UNION ALL
SELECT 'remove_left', CAST(COUNT(*) AS CHAR) FROM `orangetest`.`orange_language_reference` WHERE locale_code IN ('af','am','az','bs','ca','ceb','co','cy','eo','et','eu','fi','fy','gd','gl','gu','ha','haw','hmn','ht','hu','ig','jv','kk','km','ku','ky','lb','lo','lt','lv','mg','mi','mk','ml','mn','mr','ms','mt','my','ny','or','pa','ps','rw','sd','sk','sl','sm','sn','sq','sr','st','su','sw','ta','te','tg','uz','xh','yi','yo','zu')
HAVING COUNT(*) <> 0
UNION ALL
SELECT 'content_count', CAST(COUNT(*) AS CHAR) FROM `orangetest`.`orange_content_locale_text`
HAVING COUNT(*) <> 0
UNION ALL
SELECT 'role_count', CAST(COUNT(*) AS CHAR) FROM `orangetest`.`orange_country_locale_role`
HAVING COUNT(*) <> 18
UNION ALL
SELECT 'brand_count', CAST(COUNT(*) AS CHAR) FROM `orangetest`.`orange_brand_identity_translations`
HAVING COUNT(*) <> 12
UNION ALL
SELECT CONCAT('keep:', e.locale_code), 'mismatch' FROM (
SELECT '1' AS id, 'ar' AS locale_code, 'b2dd7b55fa23060ea4e8581e10bcb1cdcefa81df5e33d5c07620db0f5ee02000' AS row_sha256
UNION ALL
SELECT '2', 'en', '14ebc17f3a8a2674dec2b2404c220e3e7aee5ee6a6bc27d01632f124d21f5944'
UNION ALL
SELECT '3', 'fil', '9a532f459ae7825321ff6d500a1113e6cbf3cb767197c864d084e3dac6a0360b'
UNION ALL
SELECT '4', 'hi', '46e4843f4853ff564bbfdfdcfed3006f9001f8edefd903ff4156fd95e897bb5f'
UNION ALL
SELECT '8', 'be', '7b043008fe77065f1e85a4cb2bb7a51c222d9ca61b0ac8b09f499335fdc92402'
UNION ALL
SELECT '9', 'bg', '5102b3841d83b773ec9242be222577ab2b403dab2f195ddc8ba4dc26baef34d7'
UNION ALL
SELECT '10', 'bn', 'cb7569a844c995f9eaa66ab0cc0b7d4697e613cdbebee029448ae8b0e02de8fc'
UNION ALL
SELECT '15', 'cs', '9ca4e48db7cab8affc0a5ceb6a84d33417b453e43bd72ea1438accccdd9b4e82'
UNION ALL
SELECT '17', 'da', 'ef33c8cf81022229cd5beec25e1729712a72063ef1baa5cdc541fbcb70e9c5f9'
UNION ALL
SELECT '18', 'de', '5405f63d8baea515b818c0eeb5e85d8f065579fbc113e9e39b4de4575f356400'
UNION ALL
SELECT '19', 'el', 'e11cdf4f27197cfd71f59d7b3a634ef1b72589170a1d1fa5d8b0463e0ed25716'
UNION ALL
SELECT '21', 'es', 'b42b2dc2ba942b1448330fb1fa22d6467f480ff18770d95861e5b15b87dd539e'
UNION ALL
SELECT '24', 'fa', '0350c07da6c7bc1da362f17c0e635308d76485b0ba34d2c0d25e8e55567b128c'
UNION ALL
SELECT '26', 'fr', 'c6ad97598b15f57e3e8878f4135c5ea95636004a45127f2aebd294047fc2745f'
UNION ALL
SELECT '28', 'ga', 'e3bbd6628d198ced34063b95feb2f8a76eb99c81a90f8c01512650f548749d42'
UNION ALL
SELECT '34', 'he', 'edcdb6d6d75c21c5180c426903e9b88a8b3f45df6ecc18e9b9ad8a2f13d98e2c'
UNION ALL
SELECT '36', 'hr', 'f99358920b82e09ded9f017ee1588b8a3d46dec23e8f12ce00b082d8cd0b7535'
UNION ALL
SELECT '39', 'hy', '46aeb2b98958a1932c02f9b9ca2c22c0f1b694beaada2cbea6f5d394bd949642'
UNION ALL
SELECT '40', 'id', 'f186f8897a9971a1c6ca058aa1c06e81cc1689a1a97d3e9ef84973678f32a881'
UNION ALL
SELECT '42', 'is', 'b70c87df0de9ecf9ec0d1b358d21d33df4dec8412f65f58c8b15bf7146eec8a5'
UNION ALL
SELECT '43', 'it', 'fa296c78ce2f847a4872640596d2a843891222f616fb71554cd51e2206bcba25'
UNION ALL
SELECT '44', 'ja', '2adc73a5124900546905cce15aa1e200d0fcf7c5843c145c23c5257bbb912073'
UNION ALL
SELECT '46', 'ka', 'e33d78ef775815b80c1005ddd59e33069746422e63c4a6f4d623789e02988b7a'
UNION ALL
SELECT '49', 'kn', '7d81a405eab1125ad659c251c23f5e45d637b32a7c204c2c50e7500b15dd57e4'
UNION ALL
SELECT '50', 'ko', '76b5d9ced8448d2456bad5322908676306615a7d48de2ffb261b31853d08113d'
UNION ALL
SELECT '66', 'ne', 'b29e83a85aaeaf86ecad65d128cf572b05715517782e73b83636d8aa29158584'
UNION ALL
SELECT '67', 'nl', '32945ddc790d0c5d69670bae2357d0f4c1b9181aaf1469cb0e86736381f6fa15'
UNION ALL
SELECT '68', 'no', '87f3bc323485a57d2a7f80e2909426edf42a077c0cefec49a16934caba16dc46'
UNION ALL
SELECT '72', 'pl', 'd5d5b070ce2a9cd1fc4898efa18fd409b176fb65938e7ddbaa519a1c2ee2fa2c'
UNION ALL
SELECT '74', 'pt', '586fb83b8d8542c832541180d6ff9f1f6571e95b360019d420d816098f442e93'
UNION ALL
SELECT '75', 'ro', '4f9c4a01f7832f7c6994365f7866b9f7e75c869b18cfc3ab87f3a0ffc7853beb'
UNION ALL
SELECT '76', 'ru', 'c59b34381f971b117973daea73c113d8cd829d67ab72f0df6571fb6c097d2b04'
UNION ALL
SELECT '79', 'si', '0b80668d4bdd958acf35b6b101f4c1e28b77686f9162599aaccbfad34a471cd4'
UNION ALL
SELECT '84', 'so', '41148a5fa0b90d63093182109a210a03296794d6281c0ba475fa8d47d6c49b85'
UNION ALL
SELECT '89', 'sv', '6572c379f5ac283cea204d9756ba117571614f4c219bdd483d21366cc0bce630'
UNION ALL
SELECT '94', 'th', 'a80f8d7142f341622e3f5f9cd5a2e9c540a6add4ae9ee5a2d42c6582fdc88c39'
UNION ALL
SELECT '95', 'tr', 'f1471977bc5622f3aa436b4a64244fe94c1c63e42ca24f102fed6abf0b70de4f'
UNION ALL
SELECT '96', 'uk', 'b462862e6b98357673f9b90b29bacc300912f846177371bbd9df4efe94201994'
UNION ALL
SELECT '97', 'ur', 'cea12b5cd26a2cc6899d891edbd04260fd80fa466884697ffba72cb9b5b63895'
UNION ALL
SELECT '99', 'vi', '330708dcc10d47053b2403851d6b041796ccc48321c1ec973420a9ae597d6ceb'
UNION ALL
SELECT '103', 'zh', '60059da1c2470629af4e6903a1fe32176bc1e36d5f6c64f6e0d9659f09b3735f'
) e
LEFT JOIN (SELECT id, locale_code, SHA2(CONCAT(CASE WHEN `id` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`id` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`id` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `locale_code` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`locale_code` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`locale_code` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `label_en` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`label_en` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`label_en` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `label_native` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`label_native` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`label_native` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `aliases_json` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`aliases_json` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`aliases_json` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `dir` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`dir` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`dir` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `is_active` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`is_active` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`is_active` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `sort_order` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`sort_order` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`sort_order` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `created_at` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`created_at` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`created_at` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `updated_at` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`updated_at` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`updated_at` AS CHAR) USING utf8mb4)))) END), 256) AS row_sha256 FROM `orangetest`.`orange_language_reference`) a
  ON a.id = e.id AND a.locale_code = e.locale_code AND a.row_sha256 = e.row_sha256
WHERE a.id IS NULL
UNION ALL
SELECT CONCAT('role:', e.locale_code, ':', e.role), 'mismatch' FROM (
SELECT '1' AS country_id, 'ar' AS locale_code, 'admin_ui' AS role, 'f2d44fbfade0858b53450e0a2bda017360a1a1ceb896f51040a309a04c6ce95e' AS row_sha256
UNION ALL
SELECT '1', 'ar', 'base', '1a4cc5791bb1b418cfdc058cd26fb37431eab233d8605e67b4a0f82ce7cccb26'
UNION ALL
SELECT '1', 'ar', 'content', '48d18636a270846f38e4e4fc082c92602f22b78e1f412720a268c64c30ec4e2a'
UNION ALL
SELECT '1', 'ar', 'customer', 'bda4bf3a081a5e584cd0cab5f41dec15236a75a3e136110c855a9af2d7ca1ace'
UNION ALL
SELECT '1', 'de', 'customer', 'b1d631680ff35afaad560b71f3ffabb4ae32d34bd0c9a2fe982d6c7684961321'
UNION ALL
SELECT '1', 'en', 'admin_ui', '344d751d1d4cb4867ec896cb89ed2d60b62af38cd5c6b3879400a168ce7a9eb6'
UNION ALL
SELECT '1', 'en', 'content', '7c7dd6488d95b9cd0e8c1219876e5d0accce416e16287612af37847f627f448b'
UNION ALL
SELECT '1', 'en', 'customer', '6734e667c71ba237016cde9cf283cd346abd20f252562917d93693027fab2bae'
UNION ALL
SELECT '1', 'es', 'customer', 'c004454ad581a19f2ff3d45d96ee7c76e240db545f5c73e2255109ec0facc57c'
UNION ALL
SELECT '1', 'fa', 'customer', 'd69d0c424e82b06144377296c1ac04d004d668fe23e5dab044c0b5021b512642'
UNION ALL
SELECT '1', 'fil', 'customer', '9fa05c8e9d387e569e94d83121c585fd78782c588a55600bdd1e27bb274bd0d3'
UNION ALL
SELECT '1', 'fr', 'customer', 'db1ca4eafdf8c32c83e35543447fce8ab8b77d9a57342094bcffd043b2dd5147'
UNION ALL
SELECT '1', 'he', 'customer', 'd4c7ca63cd7eaeffe2cf90329ad46809bffd87ece294d298d26d476e251d294c'
UNION ALL
SELECT '1', 'hi', 'customer', '43f1eb46c1a2c7c85a44733f0f30dcb4e4b9a29835189702b1d4950cdaf5134f'
UNION ALL
SELECT '1', 'ru', 'customer', 'fce5ae2c7b09bde179824e7d85e0c6af8b41b7bcf26fae2b0906594c78ef4509'
UNION ALL
SELECT '1', 'tr', 'customer', '54b3afb1a5d87ebfa803367b70e166e3171bf2d11be13f897823bf7c57e71ca5'
UNION ALL
SELECT '1', 'ur', 'customer', '2122203450fb62c6af72ec0468b99af1564cab5a2a86fb5be143b9fbf637689c'
UNION ALL
SELECT '1', 'zh', 'customer', 'd45e1cf9f40088719a068dfc2bca6e2c26ce13d4b23ec6432eb93807368d3467'
) e
LEFT JOIN (SELECT country_id, locale_code, role, SHA2(CONCAT(CASE WHEN `country_id` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`country_id` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`country_id` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `locale_code` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`locale_code` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`locale_code` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `role` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`role` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`role` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `sort_order` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`sort_order` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`sort_order` AS CHAR) USING utf8mb4)))) END), 256) AS row_sha256 FROM `orangetest`.`orange_country_locale_role`) a
  ON a.country_id = e.country_id AND a.locale_code = e.locale_code AND a.role = e.role AND a.row_sha256 = e.row_sha256
WHERE a.country_id IS NULL
UNION ALL
SELECT CONCAT('brand:', e.id), 'mismatch' FROM (
SELECT '1' AS id, 'ar' AS locale, '8f42e392d367d6dd62024f96058688a56bb9ce0654d3f9de12b158848ef6a859' AS row_sha256
UNION ALL
SELECT '2', 'en', 'b4b0e4607a9cf4bf940a15a247ef3b0baf6a07f5e70103c3b43736c3af7076eb'
UNION ALL
SELECT '3', 'fil', '7b92a83714a8c7763df49ec041523471128742bed49a6b8b90f3ea00dc865397'
UNION ALL
SELECT '4', 'hi', '17eee4a8f7fee2417e13d1809256bb54a3e5d00f92620ed8eb80f689f45437e5'
UNION ALL
SELECT '5', 'ar', '4f79ee635ab915fca1ebc997176ca0ed0d7a4dbfc994e10511c97ee4604be1a0'
UNION ALL
SELECT '6', 'en', '650140fcb6f6a2506ce25ca2b404241def41fa1c1b2fe896921bbef3c3d4d684'
UNION ALL
SELECT '7', 'fil', '9ee0f2a4d4ac3bdf459fa114cb9a97cc93ad9b7a6779b5d761b720af21467395'
UNION ALL
SELECT '8', 'hi', 'e72c5314421a7467c452f8857938786cd912331ec2ac18bb205d6be970040b2e'
UNION ALL
SELECT '9', 'ar', 'f8ca6741ee16b7fa1c92998fbfe60bdd8e59b83da010bcedc0dd8c728e3b533b'
UNION ALL
SELECT '10', 'en', 'ba72f3558d8e7d69ac50f2e46a5a2ee74b9995147fcdd05e9dc15d902b796e57'
UNION ALL
SELECT '11', 'fil', '83504d10079d28d6ce2487db5a151e6ba5918048f1b87145bcad7b52e1862125'
UNION ALL
SELECT '12', 'hi', '781a6f28e19e8e96660c77158b534190338bf6d32d1e58c3019257677ad7cb6b'
) e
LEFT JOIN (SELECT id, locale, SHA2(CONCAT(CASE WHEN `id` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`id` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`id` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `identity_version_id` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`identity_version_id` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`identity_version_id` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `locale` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`locale` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`locale` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `text_key` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`text_key` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`text_key` AS CHAR) USING utf8mb4)))) END, CHAR(31), CASE WHEN `text_value` IS NULL THEN 'N' WHEN CHAR_LENGTH(CAST(`text_value` AS CHAR)) = 0 THEN 'E' ELSE CONCAT('H', LOWER(HEX(CONVERT(CAST(`text_value` AS CHAR) USING utf8mb4)))) END), 256) AS row_sha256 FROM `orangetest`.`orange_brand_identity_translations`) a
  ON a.id = e.id AND a.locale = e.locale AND a.row_sha256 = e.row_sha256
WHERE a.id IS NULL;
