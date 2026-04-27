-- إعادة تسمية مفتاح إعداد القيد التلقائي القديم purchase_grni إلى purchase_clearing
-- عندما لا يوجد صف purchase_clearing (تجنب تعارض المفتاح الأساسي).
UPDATE orange_gl_account_settings g
LEFT JOIN orange_gl_account_settings c ON c.setting_key = 'purchase_clearing'
SET g.setting_key = 'purchase_clearing'
WHERE g.setting_key = 'purchase_grni' AND c.setting_key IS NULL;
