-- إزالة جذر الدليل الافتراضي «الحسابات التحليلية» (كود 7) إن وُجد بدون أبناء.
-- إن وُجدت حسابات فرعية تحت هذا الجذر فلن يُحذف الصف؛ يجب نقلها أو حذفها يدوياً أولاً.
DELETE FROM accounts
WHERE TRIM(code) = '7'
  AND (parent_id IS NULL OR parent_id = 0)
  AND NOT EXISTS (SELECT 1 FROM accounts AS c WHERE c.parent_id = accounts.id);
