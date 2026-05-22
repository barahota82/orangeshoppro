-- إزالة جذر «الحسابات التحليلية» القديم (كود 7) إن وُجد بدون أبناء — per country بعد v52.
-- لا يحذف جذر «حسابات نظامية (خارج الميزانية)» الحالي.
-- إن وُجدت حسابات فرعية تحت هذا الجذر فلن يُحذف الصف؛ انقلها أو احذفها يدوياً أولاً.

DELETE a FROM accounts a
WHERE TRIM(a.code) = '7'
  AND (a.parent_id IS NULL OR a.parent_id = 0)
  AND (
    a.name = 'الحسابات التحليلية'
    OR a.name_en = 'Analytical accounts'
  )
  AND NOT EXISTS (SELECT 1 FROM accounts AS c WHERE c.parent_id = a.id);
