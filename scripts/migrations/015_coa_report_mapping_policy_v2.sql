-- مواءمة دليل الحسابات وسطر التقرير مع سياسة التقارير v2 (Orange)
-- 1) سطر «مصاريف التوريد»: تكلفة مبيعات / متاجرة (كما في الاستخدام تحت جذر 5).
-- 2) جذور الدليل ذات الكود 1–7: ربط report_line_id بالسطر المرجعي المناسب بدل other.
-- 3) مجموعة المبيعات 4101: قسم متاجرة مثل باقي فرع المبيعات.
-- 4) إيراد فروق عملة 42010300001: طبيعة دائنية لحساب إيراد.
-- 5) مجموعة مصاريف الشراء 5401: تصنيف تكم/متاجرة يتوافق مع الأبناء والشجرة.

UPDATE report_line_master
SET
    label_ar = 'مصاريف توريد وشحن (ضمن تكلفة المبيعات)',
    label_en = 'Landed cost & purchase charges (COGS)',
    account_type = 'cogs',
    report_section = 'trading',
    sort_order = 115
WHERE LOWER(TRIM(code)) = 'purchase_expenses';

UPDATE accounts a
INNER JOIN report_line_master r ON LOWER(TRIM(r.code)) = 'asset'
SET a.report_line_id = r.id
WHERE (a.parent_id IS NULL OR a.parent_id = 0)
  AND TRIM(COALESCE(a.code, '')) = '1'
  AND a.is_group = 1;

UPDATE accounts a
INNER JOIN report_line_master r ON LOWER(TRIM(r.code)) = 'liability'
SET a.report_line_id = r.id
WHERE (a.parent_id IS NULL OR a.parent_id = 0)
  AND TRIM(COALESCE(a.code, '')) = '2'
  AND a.is_group = 1;

UPDATE accounts a
INNER JOIN report_line_master r ON LOWER(TRIM(r.code)) = 'equity'
SET a.report_line_id = r.id
WHERE (a.parent_id IS NULL OR a.parent_id = 0)
  AND TRIM(COALESCE(a.code, '')) = '3'
  AND a.is_group = 1;

UPDATE accounts a
INNER JOIN report_line_master r ON LOWER(TRIM(r.code)) = 'revenue'
SET a.report_line_id = r.id
WHERE (a.parent_id IS NULL OR a.parent_id = 0)
  AND TRIM(COALESCE(a.code, '')) = '4'
  AND a.is_group = 1;

UPDATE accounts a
INNER JOIN report_line_master r ON LOWER(TRIM(r.code)) = 'cogs'
SET a.report_line_id = r.id
WHERE (a.parent_id IS NULL OR a.parent_id = 0)
  AND TRIM(COALESCE(a.code, '')) = '5'
  AND a.is_group = 1;

UPDATE accounts a
INNER JOIN report_line_master r ON LOWER(TRIM(r.code)) = 'expense'
SET a.report_line_id = r.id
WHERE (a.parent_id IS NULL OR a.parent_id = 0)
  AND TRIM(COALESCE(a.code, '')) = '6'
  AND a.is_group = 1;

UPDATE accounts a
INNER JOIN report_line_master r ON LOWER(TRIM(r.code)) = 'other'
SET a.report_line_id = r.id
WHERE (a.parent_id IS NULL OR a.parent_id = 0)
  AND TRIM(COALESCE(a.code, '')) = '7'
  AND a.is_group = 1;

UPDATE accounts
SET report_section = 'trading'
WHERE TRIM(COALESCE(code, '')) = '4101'
  AND is_group = 1;

UPDATE accounts
SET normal_balance = 'credit'
WHERE TRIM(COALESCE(code, '')) = '42010300001'
  AND is_group = 0;

UPDATE accounts
SET account_type = 'cogs',
    report_section = 'trading'
WHERE TRIM(COALESCE(code, '')) = '5401'
  AND is_group = 1;
