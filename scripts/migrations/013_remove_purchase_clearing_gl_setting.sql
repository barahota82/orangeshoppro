-- إزالة مفاتيح «تمرير المشتريات» القديمة من إعدادات القيود التلقائية (لم تعد مستخدمة).
DELETE FROM orange_gl_setting_alloc WHERE setting_key IN ('purchase_clearing', 'purchase_grni');
DELETE FROM orange_gl_account_settings WHERE setting_key IN ('purchase_clearing', 'purchase_grni');
