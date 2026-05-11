-- مسودات أدلة مقاس استرشادية بدون عائلة (حفظ ثم ربط لاحقاً).
-- آمن لإعادة التشغيل: يعدّل العمود فقط إن كان NOT NULL.

ALTER TABLE advisory_sizing_guides
  MODIFY COLUMN size_family_id INT NULL DEFAULT NULL;
