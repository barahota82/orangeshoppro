-- إن ظهر خطأ Unknown column 'payment_terms' عند حفظ قواعد ربط أنواع اليومية، نفّذ هذا على قاعدة البرتقالي.
-- (يفضّل الاعتماد على orange_catalog_ensure_gl_account_settings_alloc_tables من التطبيق؛ هذا للطوارئ اليدوية.)

ALTER TABLE orange_gl_journal_type_rules
  ADD COLUMN payment_terms VARCHAR(8) NOT NULL DEFAULT '' COMMENT 'cash|credit for PIN/PDN; empty=standard' AFTER journal_type_id;

-- إن وُجد فهرس قديم على journal_type_id وحده:
-- ALTER TABLE orange_gl_journal_type_rules DROP INDEX uq_ojtr_journal_type;

ALTER TABLE orange_gl_journal_type_rules
  ADD UNIQUE KEY uq_ojtr_jt_terms (journal_type_id, payment_terms);

UPDATE orange_gl_journal_type_rules r
INNER JOIN journal_types jt ON jt.id = r.journal_type_id AND jt.code IN ('PIN', 'PDN')
SET r.payment_terms = 'cash'
WHERE r.payment_terms = '';
