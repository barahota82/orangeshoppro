-- 069 — ضبط عدّاد AUTO_INCREMENT لجداول الشجرة الموحّدة بعد حذف فرع «العروض» من الكتالوج.
-- الهدف: إزالة فجوة العدّاد في النهاية بحيث يبدأ أول إدراج قادم مباشرةً بعد آخر id موجود.
-- آمن وقابل لإعادة التشغيل: InnoDB لا يضبط العدّاد لأقل من MAX(id)+1، فإن كانت القاعدة
-- أحدث من القيم أدناه يُصحَّح العدّاد تلقائياً للأعلى؛ ولا يُلمس أي صف أو مفتاح أجنبي.

ALTER TABLE departments AUTO_INCREMENT = 11;

ALTER TABLE catalog_sections AUTO_INCREMENT = 13;

ALTER TABLE catalog_categories AUTO_INCREMENT = 62;

ALTER TABLE catalog_subcategories AUTO_INCREMENT = 154;

ALTER TABLE product_types AUTO_INCREMENT = 187;
