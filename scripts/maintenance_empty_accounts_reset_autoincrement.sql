-- -----------------------------------------------------------------------------
-- إفراغ جدول accounts وإعادة AUTO_INCREMENT إلى 1
-- ⚠ احتفظ بنسخة احتياطية. هذا المسار يدمّر اتساق القيود المحاسبية إن وُجدت قيود.
-- -----------------------------------------------------------------------------
-- الجداول التي تشير إلى accounts في المخطط المرجعي (قد تختلف قاعدتك):
--   journal_lines.account_id, orange_gl_account_settings.account_id,
--   orange_gl_pending_movements (account_debit / account_credit), expenses.expense_account_id,
--   accounts.parent_id (شجرة ذاتية).
--
-- الخيار أدناه: مسح سلسلة السندات المرتبطة بالقيود ثم الحسابات (مناسب لبيئة تجريبية
-- أو إعادة بناء كاملة للدفتر). عدّل القائمة إن كان لديك جداول إضافية.
-- -----------------------------------------------------------------------------

START TRANSACTION;

SET FOREIGN_KEY_CHECKS = 0;

-- تفريغ سلسلة القيود والمرجعيات (ترتيب آمن مع إعادة تفعيل المفاتيح لاحقاً)
DELETE FROM orange_gl_pending_movements;
DELETE FROM party_subledger_allocations;
DELETE FROM party_subledger;
DELETE FROM journal_lines;
DELETE FROM journal_vouchers;

DELETE FROM orange_gl_account_settings;

UPDATE expenses SET expense_account_id = NULL WHERE expense_account_id IS NOT NULL;

-- قديم: journal_entries (قواعد تراثية فقط)
-- DELETE FROM journal_entries;

DELETE FROM accounts;

ALTER TABLE accounts AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

COMMIT;

-- بعد التنفيذ: عند أول تحميل للتطبيق على جدول accounts فارغ يُعاد بذر الجذور الافتراضية (1–7)
-- عبر orange_catalog_seed_default_accounts_if_empty()، أو أعد إدراج الدليل يدوياً.
