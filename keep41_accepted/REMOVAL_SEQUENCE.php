<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
return file_get_contents(__FILE__, false, null, __COMPILER_HALT_OFFSET__);
__halt_compiler();-- NOT authorized for the hosted server. Isolated proof or a later owner session only.
-- Does not change the selected database. Rejects NULL or any database other than orangetest before any change.
-- Rejects an open transaction before START TRANSACTION. Does not COMMIT or ROLLBACK that transaction.
-- Sets REPEATABLE READ only after those gates, immediately before its own transaction, so FOR UPDATE gap-locks the empty content table.
-- Checks the four tables are InnoDB before locking or deleting. The server version string is not that check.
-- Does not acquire the backup lock or the restore locks.
-- Source this file with --abort-source-on-error. A database rejection signals after its result row and stops the rest of the file before any change.
-- An open transaction is reported and left untouched. A difference result does not close the session. COMMIT follows only a passed re-read.
SET NAMES utf8mb4;
DELIMITER $$
BEGIN NOT ATOMIC
  IF DATABASE() IS NULL OR DATABASE() <> 'orangetest' THEN
    SELECT 'REJECTED_DATABASE' AS session_result, IFNULL(DATABASE(), 'NULL') AS detail_rows;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'REJECTED_DATABASE';
  ELSEIF @@in_transaction <> 0 THEN
    SELECT 'REJECTED_OPEN_TRANSACTION' AS session_result, @@in_transaction AS detail_rows;
  END IF;
END$$
DELIMITER ;
DELIMITER $$
BEGIN NOT ATOMIC
  DECLARE v_diff INT DEFAULT 0;
  DECLARE v_deleted INT DEFAULT 0;
  DECLARE v_innodb INT DEFAULT 0;
  DECLARE v_own TINYINT DEFAULT 0;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    IF v_own = 1 THEN
      ROLLBACK;
    END IF;
    SELECT 'ROLLED_BACK_ERROR' AS session_result, 0 AS detail_rows;
  END;
  IF DATABASE() IS NULL OR DATABASE() <> 'orangetest' THEN
    SELECT 'REJECTED_DATABASE' AS session_result, IFNULL(DATABASE(), 'NULL') AS detail_rows;
  ELSEIF @@in_transaction <> 0 THEN
    SELECT 'REJECTED_OPEN_TRANSACTION' AS session_result, @@in_transaction AS detail_rows;
  ELSE
    SELECT COUNT(*) INTO v_innodb
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'orangetest'
       AND TABLE_NAME IN (
         'orange_language_reference',
         'orange_country_locale_role',
         'orange_content_locale_text',
         'orange_brand_identity_translations'
       )
       AND UPPER(ENGINE) = 'INNODB';
    IF v_innodb <> 4 THEN
      SELECT 'REJECTED_ENGINE' AS session_result, v_innodb AS detail_rows;
    ELSE
      DROP TEMPORARY TABLE IF EXISTS keep41_check;
      CREATE TEMPORARY TABLE keep41_check (
        check_id VARCHAR(191) NOT NULL,
        found VARCHAR(191) NOT NULL
      ) ENGINE=InnoDB;
      SET TRANSACTION ISOLATION LEVEL REPEATABLE READ;
      START TRANSACTION;
      SET v_own = 1;
      SELECT id FROM `orangetest`.`orange_language_reference` FOR UPDATE;
      SELECT country_id FROM `orangetest`.`orange_country_locale_role` FOR UPDATE;
      SELECT id FROM `orangetest`.`orange_brand_identity_translations` FOR UPDATE;
      SELECT id FROM `orangetest`.`orange_content_locale_text` FOR UPDATE;
      DELETE FROM keep41_check;
      INSERT INTO keep41_check
      SELECT 'database' AS check_id, IFNULL(DATABASE(), 'NULL') AS found
WHERE DATABASE() IS NULL OR DATABASE() <> 'orangetest'
UNION ALL
SELECT 'engine', @@version
WHERE @@version NOT LIKE '10.11.18-MariaDB%'
UNION ALL
SELECT 'reference_count', CAST(COUNT(*) AS CHAR) FROM `orangetest`.`orange_language_reference`
HAVING COUNT(*) <> 104
UNION ALL
SELECT 'remove_present', CAST(COUNT(*) AS CHAR) FROM `orangetest`.`orange_language_reference` WHERE locale_code IN ('af','am','az','bs','ca','ceb','co','cy','eo','et','eu','fi','fy','gd','gl','gu','ha','haw','hmn','ht','hu','ig','jv','kk','km','ku','ky','lb','lo','lt','lv','mg','mi','mk','ml','mn','mr','ms','mt','my','ny','or','pa','ps','rw','sd','sk','sl','sm','sn','sq','sr','st','su','sw','ta','te','tg','uz','xh','yi','yo','zu')
HAVING COUNT(*) <> 63
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
SELECT CONCAT('reference:', e.locale_code), 'mismatch' FROM (
SELECT '1' AS id, 'ar' AS locale_code, 'b2dd7b55fa23060ea4e8581e10bcb1cdcefa81df5e33d5c07620db0f5ee02000' AS row_sha256
UNION ALL
SELECT '2', 'en', '14ebc17f3a8a2674dec2b2404c220e3e7aee5ee6a6bc27d01632f124d21f5944'
UNION ALL
SELECT '3', 'fil', '9a532f459ae7825321ff6d500a1113e6cbf3cb767197c864d084e3dac6a0360b'
UNION ALL
SELECT '4', 'hi', '46e4843f4853ff564bbfdfdcfed3006f9001f8edefd903ff4156fd95e897bb5f'
UNION ALL
SELECT '5', 'af', 'b25ecb95f27c415765224f5e2461913e853fdae3d2377ba36fb246cd6caedeb7'
UNION ALL
SELECT '6', 'am', '1153a5a6851b3391ac48e16f8a7cdfa59f615d33f1a5ab4d8050eebdf07f00f3'
UNION ALL
SELECT '7', 'az', '4a0d5128da7f2c8e6349d8c0fb610f8af3a89abc06d010a851dac41e68f199a9'
UNION ALL
SELECT '8', 'be', '7b043008fe77065f1e85a4cb2bb7a51c222d9ca61b0ac8b09f499335fdc92402'
UNION ALL
SELECT '9', 'bg', '5102b3841d83b773ec9242be222577ab2b403dab2f195ddc8ba4dc26baef34d7'
UNION ALL
SELECT '10', 'bn', 'cb7569a844c995f9eaa66ab0cc0b7d4697e613cdbebee029448ae8b0e02de8fc'
UNION ALL
SELECT '11', 'bs', '1fa18e08a9df2d1a94357e22b3ffa36a216411ce38a115b97843e6e55d5bef70'
UNION ALL
SELECT '12', 'ca', '429e44de1a92034cc9a937d4e18bd3cd4963cb9e878de5066d2c1bf8b9d57b05'
UNION ALL
SELECT '13', 'ceb', '8079e0683fc9066fe23a8ae889be38302ea45a5a64e05def532be8ce8196299e'
UNION ALL
SELECT '14', 'co', 'a5939cc7a7b5b3f6b1c985e68bc1ec979e2ebfeb3dc9a4b3e3cc1e3b9e8a460e'
UNION ALL
SELECT '15', 'cs', '9ca4e48db7cab8affc0a5ceb6a84d33417b453e43bd72ea1438accccdd9b4e82'
UNION ALL
SELECT '16', 'cy', 'a7bfd58a1928624ee2952015464bd6780a2bc3bfeec413196844c8cd2ade464b'
UNION ALL
SELECT '17', 'da', 'ef33c8cf81022229cd5beec25e1729712a72063ef1baa5cdc541fbcb70e9c5f9'
UNION ALL
SELECT '18', 'de', '5405f63d8baea515b818c0eeb5e85d8f065579fbc113e9e39b4de4575f356400'
UNION ALL
SELECT '19', 'el', 'e11cdf4f27197cfd71f59d7b3a634ef1b72589170a1d1fa5d8b0463e0ed25716'
UNION ALL
SELECT '20', 'eo', '06fdd43357c101d4506635008e266e22a3a4f0013b79f102b871181a3b77d8e9'
UNION ALL
SELECT '21', 'es', 'b42b2dc2ba942b1448330fb1fa22d6467f480ff18770d95861e5b15b87dd539e'
UNION ALL
SELECT '22', 'et', '961320739e15934d752bbf55ac9643c57d15d6addda7f28ccf2cd62ac5644db9'
UNION ALL
SELECT '23', 'eu', '7e8b95beab3a5983ad7976eabd7aca429417e609e98ae40003bad535e593f2c9'
UNION ALL
SELECT '24', 'fa', '0350c07da6c7bc1da362f17c0e635308d76485b0ba34d2c0d25e8e55567b128c'
UNION ALL
SELECT '25', 'fi', '059c0ae520bed0402d5ba007e1aec6216696997c40280b3ae1290a949da7b02c'
UNION ALL
SELECT '26', 'fr', 'c6ad97598b15f57e3e8878f4135c5ea95636004a45127f2aebd294047fc2745f'
UNION ALL
SELECT '27', 'fy', '567b5e00984c840a210a168f86dfc5067d536cc6cde91da8fbfbd236deb4c844'
UNION ALL
SELECT '28', 'ga', 'e3bbd6628d198ced34063b95feb2f8a76eb99c81a90f8c01512650f548749d42'
UNION ALL
SELECT '29', 'gd', '9b2fb3149c01e46cb439d6bdf8426f00ad39397fd22b86b92ac37dc1177940f5'
UNION ALL
SELECT '30', 'gl', 'd4a2dd0fb81ae7953bc79a55411b97d75e82af3cac77c0388a16180f248a9766'
UNION ALL
SELECT '31', 'gu', '4a4e39ffc33761ae2f88d24e9465557b533710b95ea49a1511d05192ff756849'
UNION ALL
SELECT '32', 'ha', '107d1fa23632b48b016743d4ad0d94c15facb3fcde93721a051f162a66d631f9'
UNION ALL
SELECT '33', 'haw', '275a8844b3a89a133254d4dfa2bf6b65123a12eccd4e707633b28f36c2f5c6f3'
UNION ALL
SELECT '34', 'he', 'edcdb6d6d75c21c5180c426903e9b88a8b3f45df6ecc18e9b9ad8a2f13d98e2c'
UNION ALL
SELECT '35', 'hmn', '9c32bf144be42d52baeb66a514134e5b4b5b47f85acb65fb22ab079e5cb48c08'
UNION ALL
SELECT '36', 'hr', 'f99358920b82e09ded9f017ee1588b8a3d46dec23e8f12ce00b082d8cd0b7535'
UNION ALL
SELECT '37', 'ht', '22803ce1e8314ce63db86b0d4a5744ed6257559c993b5b1931df5c890483766f'
UNION ALL
SELECT '38', 'hu', '73153d720f09c6eb2b98e91b44fec6544cf97d9221338b04d7292a74f691a466'
UNION ALL
SELECT '39', 'hy', '46aeb2b98958a1932c02f9b9ca2c22c0f1b694beaada2cbea6f5d394bd949642'
UNION ALL
SELECT '40', 'id', 'f186f8897a9971a1c6ca058aa1c06e81cc1689a1a97d3e9ef84973678f32a881'
UNION ALL
SELECT '41', 'ig', '7416091d11a7e5c53e010643c15fa3046b8954e26b5c5842930847ced8da00fc'
UNION ALL
SELECT '42', 'is', 'b70c87df0de9ecf9ec0d1b358d21d33df4dec8412f65f58c8b15bf7146eec8a5'
UNION ALL
SELECT '43', 'it', 'fa296c78ce2f847a4872640596d2a843891222f616fb71554cd51e2206bcba25'
UNION ALL
SELECT '44', 'ja', '2adc73a5124900546905cce15aa1e200d0fcf7c5843c145c23c5257bbb912073'
UNION ALL
SELECT '45', 'jv', 'c79be129846c01891a3452da0735fdfba76f269dccb1c5188d458783f89fe861'
UNION ALL
SELECT '46', 'ka', 'e33d78ef775815b80c1005ddd59e33069746422e63c4a6f4d623789e02988b7a'
UNION ALL
SELECT '47', 'kk', '1499e8c4d2c3de4de6b03e74d1bf762b7a610d0cee012c53f2bf3477b6b34924'
UNION ALL
SELECT '48', 'km', 'edae8355ddfc6ab623760a91e60f79f40cb743d16007a73c5ab3a588b3c8c627'
UNION ALL
SELECT '49', 'kn', '7d81a405eab1125ad659c251c23f5e45d637b32a7c204c2c50e7500b15dd57e4'
UNION ALL
SELECT '50', 'ko', '76b5d9ced8448d2456bad5322908676306615a7d48de2ffb261b31853d08113d'
UNION ALL
SELECT '51', 'ku', '6685b42b0cb711189af6c0433f3d750e1e3f51f129eb452f3d993862c59cb7b6'
UNION ALL
SELECT '52', 'ky', '90e8760a0caed3ac3fbbcb00b6a4b4ea375979c07630d254125f90df463c7f46'
UNION ALL
SELECT '53', 'lb', '94202eaddaac00e7b4c184cc53e8f40fb8ff02fb6857be76ce251bf9dcfefb80'
UNION ALL
SELECT '54', 'lo', 'e92eb65a1138ca5aa394a5e1642ca8d6f0d7798a670555a1d2cd25806f114e23'
UNION ALL
SELECT '55', 'lt', 'bcaf55b4c462cc456e6297aa9cd5c1af28e1757a75f6e850e0fe62065364a13d'
UNION ALL
SELECT '56', 'lv', 'dd019ca4849b5a24c6010497c62ac40ebd846d6ae49df098a5b37f493cb3eb49'
UNION ALL
SELECT '57', 'mg', 'f25ac3d7368a0ef435adce00e08d97f4f9234c472c18ee067673615af8a59ac2'
UNION ALL
SELECT '58', 'mi', '71752a7d5dfbe83dd063dc8ed7fc1c8a50ccbf214e5018a547d70af4d5f256e9'
UNION ALL
SELECT '59', 'mk', '90236e29a238757b48a806a554ab31d44c23aa31af02c32c1175909422c87570'
UNION ALL
SELECT '60', 'ml', 'efd21ccf73ea3d1363df3c3a639770fd3c6392f9eb92c56511084b1c255de631'
UNION ALL
SELECT '61', 'mn', '7d2168a807410ea8235c5eb92032495a43c90fdaa351ae15fcda28018eb143ab'
UNION ALL
SELECT '62', 'mr', '5a735deff9d427c2077f688589a7242112e374a8d36352f34d0bd46b76151f8a'
UNION ALL
SELECT '63', 'ms', '8972e96eccda01b92191684d6d6035d2f95ca01523ecf83df388dc7965d1267b'
UNION ALL
SELECT '64', 'mt', '4c1e70de75ddc694e06141e1d591e21f3e2c2c83f10e312adab3628f0b485236'
UNION ALL
SELECT '65', 'my', '5f6c17b7b5f5dc322d93755b19b47e7857365f2198a7ec37983b60e0b4baf277'
UNION ALL
SELECT '66', 'ne', 'b29e83a85aaeaf86ecad65d128cf572b05715517782e73b83636d8aa29158584'
UNION ALL
SELECT '67', 'nl', '32945ddc790d0c5d69670bae2357d0f4c1b9181aaf1469cb0e86736381f6fa15'
UNION ALL
SELECT '68', 'no', '87f3bc323485a57d2a7f80e2909426edf42a077c0cefec49a16934caba16dc46'
UNION ALL
SELECT '69', 'ny', '5e0584fe4667653aa56e5b7bf4f8da132ff12318a2aaad37ce8c103113a259f8'
UNION ALL
SELECT '70', 'or', 'c8f5f135d993a10299672ca5e9f31001f8b8d608971d03480b80d796a1e05d35'
UNION ALL
SELECT '71', 'pa', 'b29dcd0c1ea763d50a4a28e6d4c03f887149fbabecd92e13c60061d8bd2f4748'
UNION ALL
SELECT '72', 'pl', 'd5d5b070ce2a9cd1fc4898efa18fd409b176fb65938e7ddbaa519a1c2ee2fa2c'
UNION ALL
SELECT '73', 'ps', '614527b9e40916ad24fb444c747b700ea50b9f98a0f7fe4f421b9b781beb12ea'
UNION ALL
SELECT '74', 'pt', '586fb83b8d8542c832541180d6ff9f1f6571e95b360019d420d816098f442e93'
UNION ALL
SELECT '75', 'ro', '4f9c4a01f7832f7c6994365f7866b9f7e75c869b18cfc3ab87f3a0ffc7853beb'
UNION ALL
SELECT '76', 'ru', 'c59b34381f971b117973daea73c113d8cd829d67ab72f0df6571fb6c097d2b04'
UNION ALL
SELECT '77', 'rw', '20b55d9ae3313a1cb8a4aeb65cc002a5273fe2d8edcc913c5c69ad9ceab098f0'
UNION ALL
SELECT '78', 'sd', '0e99db7db86bcac8d4c0e657bbfb693f72a50d5da5866645bf3c154e116d6ba5'
UNION ALL
SELECT '79', 'si', '0b80668d4bdd958acf35b6b101f4c1e28b77686f9162599aaccbfad34a471cd4'
UNION ALL
SELECT '80', 'sk', '919c5f2958bdfc4679808e07fadc0821d133b9cbcfe5570fb67e2dd4943dd146'
UNION ALL
SELECT '81', 'sl', '0e76cde20666626dcebbd0c32b05ebed66853c58fd5293a4a9200ba6981868c8'
UNION ALL
SELECT '82', 'sm', 'b52f46094a9fe5c1c6beaae48fae4fa1a9debc668cb1fc8eb856da05793d87ba'
UNION ALL
SELECT '83', 'sn', 'cd9241fd4f292ac52fe80304ea79c448d18a68dc6524fd173e50824d61bc917c'
UNION ALL
SELECT '84', 'so', '41148a5fa0b90d63093182109a210a03296794d6281c0ba475fa8d47d6c49b85'
UNION ALL
SELECT '85', 'sq', '8619d1ffadd8746a1b19317262ca2fc15002c3c46bb016c263bb2aab435b9bd0'
UNION ALL
SELECT '86', 'sr', '052f28c1aee86b63e1b9fe1819b4e783d3506b3c2f5f3885c4fdea694550e711'
UNION ALL
SELECT '87', 'st', '156244093bdb074b2e34ee31550f014eb6090a54360921682ac387e509f433fa'
UNION ALL
SELECT '88', 'su', 'd9afb41a9954db2e097da58633d6cffa5f97d38d43787ef99886a7f32922c742'
UNION ALL
SELECT '89', 'sv', '6572c379f5ac283cea204d9756ba117571614f4c219bdd483d21366cc0bce630'
UNION ALL
SELECT '90', 'sw', 'b4e4ff27db3bb8540af29d765513f6481471f90dba423959db33dfbf5c010ca8'
UNION ALL
SELECT '91', 'ta', '4438a78fbf8c0cb42f027ce091e7ac492fb65b61dad6c3552dab7fc26a464c7e'
UNION ALL
SELECT '92', 'te', '85e732d5226958b7abec472d3e87ac0be62db0fbe0e61f4c294e9e85ae845125'
UNION ALL
SELECT '93', 'tg', 'fe314d0bfeb5953ef215cf5c842a85cf65098afe4e79156cbd5c750df7c64bab'
UNION ALL
SELECT '94', 'th', 'a80f8d7142f341622e3f5f9cd5a2e9c540a6add4ae9ee5a2d42c6582fdc88c39'
UNION ALL
SELECT '95', 'tr', 'f1471977bc5622f3aa436b4a64244fe94c1c63e42ca24f102fed6abf0b70de4f'
UNION ALL
SELECT '96', 'uk', 'b462862e6b98357673f9b90b29bacc300912f846177371bbd9df4efe94201994'
UNION ALL
SELECT '97', 'ur', 'cea12b5cd26a2cc6899d891edbd04260fd80fa466884697ffba72cb9b5b63895'
UNION ALL
SELECT '98', 'uz', '619bf5f87ec6b22652c85f03b64a6c4044e3b56d50426a503d58a86a7dc70308'
UNION ALL
SELECT '99', 'vi', '330708dcc10d47053b2403851d6b041796ccc48321c1ec973420a9ae597d6ceb'
UNION ALL
SELECT '100', 'xh', '462e551679bcd9257905126b4ca94011677950d8817d4985f89d1789e3f60667'
UNION ALL
SELECT '101', 'yi', '7fc37c4ed764c657a40087bd58204a554ebc90f9b623bea9d7414cfc2f8efc31'
UNION ALL
SELECT '102', 'yo', 'b3420afe60b09f46a8062f5c075674d368b6473bcd0bb63352ea408ebd6b90dc'
UNION ALL
SELECT '103', 'zh', '60059da1c2470629af4e6903a1fe32176bc1e36d5f6c64f6e0d9659f09b3735f'
UNION ALL
SELECT '104', 'zu', 'd3a2185bf7a2f4644c841b452d743808e572221e370502489385ab211a4bc2e0'
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
WHERE a.id IS NULL
UNION ALL
SELECT CONCAT('index:', e.table_name, ':', e.index_name, ':', e.seq_in_index), 'mismatch' FROM (
SELECT 'orange_brand_identity_translations' AS table_name, 'idx_obit_version' AS index_name, '1' AS non_unique, '1' AS seq_in_index, 'identity_version_id' AS column_name
UNION ALL
SELECT 'orange_brand_identity_translations', 'PRIMARY', '0', '1', 'id'
UNION ALL
SELECT 'orange_brand_identity_translations', 'uq_obit_version_locale_key', '0', '1', 'identity_version_id'
UNION ALL
SELECT 'orange_brand_identity_translations', 'uq_obit_version_locale_key', '0', '2', 'locale'
UNION ALL
SELECT 'orange_brand_identity_translations', 'uq_obit_version_locale_key', '0', '3', 'text_key'
UNION ALL
SELECT 'orange_content_locale_text', 'PRIMARY', '0', '1', 'id'
UNION ALL
SELECT 'orange_content_locale_text', 'uq_content_locale', '0', '1', 'entity_kind'
UNION ALL
SELECT 'orange_content_locale_text', 'uq_content_locale', '0', '2', 'entity_id'
UNION ALL
SELECT 'orange_content_locale_text', 'uq_content_locale', '0', '3', 'field_key'
UNION ALL
SELECT 'orange_content_locale_text', 'uq_content_locale', '0', '4', 'locale_code'
UNION ALL
SELECT 'orange_country_locale_role', 'PRIMARY', '0', '1', 'country_id'
UNION ALL
SELECT 'orange_country_locale_role', 'PRIMARY', '0', '2', 'locale_code'
UNION ALL
SELECT 'orange_country_locale_role', 'PRIMARY', '0', '3', 'role'
UNION ALL
SELECT 'orange_language_reference', 'PRIMARY', '0', '1', 'id'
UNION ALL
SELECT 'orange_language_reference', 'uq_olr_locale_code', '0', '1', 'locale_code'
) e
LEFT JOIN (
  SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, CAST(NON_UNIQUE AS CHAR) AS non_unique, CAST(SEQ_IN_INDEX AS CHAR) AS seq_in_index, COLUMN_NAME AS column_name
    FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = 'orangetest'
     AND TABLE_NAME IN ('orange_language_reference','orange_country_locale_role','orange_content_locale_text','orange_brand_identity_translations')
) a ON a.table_name = e.table_name AND a.index_name = e.index_name AND a.non_unique = e.non_unique AND a.seq_in_index = e.seq_in_index AND a.column_name = e.column_name
WHERE a.table_name IS NULL
UNION ALL
SELECT 'incoming_fk', CAST(COUNT(*) AS CHAR)
FROM information_schema.KEY_COLUMN_USAGE
WHERE REFERENCED_TABLE_SCHEMA = 'orangetest' AND REFERENCED_TABLE_NAME = 'orange_language_reference'
HAVING COUNT(*) <> 0
UNION ALL
SELECT 'outgoing_fk', CAST(COUNT(*) AS CHAR)
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'orangetest' AND TABLE_NAME = 'orange_language_reference' AND REFERENCED_TABLE_NAME IS NOT NULL
HAVING COUNT(*) <> 0
UNION ALL
SELECT 'trigger', CAST(COUNT(*) AS CHAR)
FROM information_schema.TRIGGERS
WHERE EVENT_OBJECT_SCHEMA = 'orangetest'
  AND EVENT_OBJECT_TABLE IN ('orange_language_reference','orange_country_locale_role','orange_content_locale_text','orange_brand_identity_translations')
HAVING COUNT(*) <> 0;
      SELECT check_id, found FROM keep41_check;
      SELECT COUNT(*) INTO v_diff FROM keep41_check;
      IF v_diff > 0 THEN
        ROLLBACK;
        SELECT 'DIFFERENCE_NEEDS_STOP_DECISION' AS session_result, v_diff AS detail_rows;
      ELSE
        DELETE FROM `orangetest`.`orange_language_reference`
          WHERE `locale_code` IN ('af','am','az','bs','ca','ceb','co','cy','eo','et','eu','fi','fy','gd','gl','gu','ha','haw','hmn','ht','hu','ig','jv','kk','km','ku','ky','lb','lo','lt','lv','mg','mi','mk','ml','mn','mr','ms','mt','my','ny','or','pa','ps','rw','sd','sk','sl','sm','sn','sq','sr','st','su','sw','ta','te','tg','uz','xh','yi','yo','zu');
        SET v_deleted = ROW_COUNT();
        IF v_deleted <> 63 THEN
          ROLLBACK;
          SELECT 'ROLLED_BACK_ROW_COUNT' AS session_result, v_deleted AS detail_rows;
        ELSE
          DELETE FROM keep41_check;
          INSERT INTO keep41_check
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
          SELECT check_id, found FROM keep41_check;
          SELECT COUNT(*) INTO v_diff FROM keep41_check;
          IF v_diff > 0 THEN
            ROLLBACK;
            SELECT 'ROLLED_BACK_POSTCHECK' AS session_result, v_diff AS detail_rows;
          ELSE
            COMMIT;
            SELECT 'COMMITTED' AS session_result, v_deleted AS detail_rows;
          END IF;
        END IF;
      END IF;
    END IF;
  END IF;
END$$
DELIMITER ;