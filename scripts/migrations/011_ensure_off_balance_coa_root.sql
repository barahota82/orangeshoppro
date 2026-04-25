-- جذر 7 الافتراضي: حسابات نظامية (خارج الميزانية) — يحدّث التسمية القديمة «الحسابات التحليلية» ويُدرج الجذر إن نُقِح (مثلاً بعد 010).
UPDATE accounts
SET
    name = 'حسابات نظامية (خارج الميزانية)',
    name_en = 'Off-balance sheet accounts'
WHERE TRIM(code) = '7'
  AND (parent_id IS NULL OR parent_id = 0)
  AND (
    name = 'الحسابات التحليلية'
    OR name_en = 'Analytical accounts'
  );

INSERT INTO accounts (name, code, parent_id, is_group, name_en, is_suspended, normal_balance)
SELECT 'حسابات نظامية (خارج الميزانية)', '7', NULL, 1, 'Off-balance sheet accounts', 0, NULL
FROM (SELECT 1 AS _x) AS _mig
WHERE NOT EXISTS (
  SELECT 1
  FROM accounts AS a
  WHERE TRIM(a.code) = '7' AND (a.parent_id IS NULL OR a.parent_id = 0)
);
