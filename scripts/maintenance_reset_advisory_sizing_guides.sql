-- Orange — إفراغ كل أدلة المقاس الاسترشادي وإعادة AUTO_INCREMENT من 1
-- نفّذ على قاعدة orange_db (أو اسم قاعدتك) بعد نسخ احتياطي.
-- ترتيب الحذف: خلايا ← صفوف ← أعمدة ← الدليل الرئيسي

-- اختياري: فك ارتباط المنتجات بدليل استرشادي (إن وُجد العمود)
-- UPDATE products SET sizing_advisory_guide_id = NULL WHERE sizing_advisory_guide_id IS NOT NULL;

DELETE FROM advisory_sizing_guide_cells;
DELETE FROM advisory_sizing_guide_rows;
DELETE FROM advisory_sizing_guide_columns;
DELETE FROM advisory_sizing_guides;

ALTER TABLE advisory_sizing_guide_cells AUTO_INCREMENT = 1;
ALTER TABLE advisory_sizing_guide_rows AUTO_INCREMENT = 1;
ALTER TABLE advisory_sizing_guide_columns AUTO_INCREMENT = 1;
ALTER TABLE advisory_sizing_guides AUTO_INCREMENT = 1;
