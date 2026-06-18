# Orange project - conversation continuity and technical decisions

> **IBRAHIM — للمحادثات الجديدة:** ابدأ من **`IBRAHIM_ORANGE_MASTER.txt`** في جذر المشروع (ورقة دخول موحّدة + تسلسل أسبقية الوثائق). **موقع هذا الملف:** `docs/archive/ORANGE_CHAT_CONTINUITY.md` — **أرشيف تقني** (ترميز، نشر، فاتورة، قرارات قديمة). المسار المعتمد للمشروع: **D:\orange** — انظر `ORANGE_PROJECT_CONTINUITY.txt` §8 (نفس المجلد).

This document records work on **Orange**: encoding, catalog, `config.php` constraints, translator removal, invoice behavior.

**Cursor rule (always on):** `.cursor/rules/orange-php-utf8-workflow.mdc`  
**This file:** human-readable log + Arabic summary below.

---

## Problems we saw

- Broken Arabic or raw PHP after **upload**; **manual paste** in the host editor often fixed it.
- `PDO::MYSQL_ATTR_INIT_COMMAND` in `config.php` **broke the site** on hosting; user does **not** want that approach; use `SET NAMES utf8mb4` inside `orange_catalog_ensure_schema()` in `includes/catalog_schema.php` instead.
- `inv_test` was not in `$allowed` in `admin/index.php` → always landed on **dashboard**; file removed.
- Invoice: opening from sidebar without `order_id` looked "broken"; page now has picker / `order_number` / recent orders.

---

## Root cause (confirmed)

Some PHP files on disk were **UTF-16 LE** (e.g. first bytes `3C 00 3F 00` instead of UTF-8 `3C 3F 70 68 70` for `<?php`). Hosting expects UTF-16 → failures; paste in web editor often saves UTF-8.

**Fix:** convert affected files to **UTF-8 without BOM** (batch was run on this repo).

---

## Deploy / upload — الطريقة الصحيحة (لا تنساها)

**الهدف:** أي ملف PHP يُرفع **UTF-8 بدون BOM** — وإلا صفحة بيضاء أو عربي مكسور.

### الطريقة المفضّلة (Git — بدون نسخ يدوي ملف ملف)

1. على الجهاز (بعد التعديل): من جذر المشروع  
   `powershell -NoProfile -File scripts\verify-php-utf8.ps1`  
   إن فشل:  
   `powershell -NoProfile -File scripts\verify-php-utf8.ps1 -Fix`  
   ثم أعد الفحص بدون `-Fix`.
2. ادفع للمستودع: `git add` → `git commit` → `git push`.
3. على السيرفر/الاستضافة: **اسحب التحديث** (`git pull`) أو انشر من نفس الـ repo — **لا** تفتح ملف PHP في لوحة الاستضافة وتلصق فيه الكود (غالباً يغيّر الترميز أو يضيف BOM).
4. **مرة واحدة لكل clone:** تفعيل الـ hook يمنع commit لو في BOM:  
   `powershell -NoProfile -File scripts\install-hooks.ps1`  
   الـ CI على GitHub يشغّل نفس الفحص على `main`/`master`.

### لو لازم رفع FTP / مدير ملفات

- ارفع الملفات كما هي من المشروع بعد ما يمرّ الفحص محلياً.
- **لا** تحفظ الملف من محرر الاستضافة بعد اللصق إن كان بيحوّل لـ UTF-16 أو «UTF-8 with BOM».
- المشروع فيه `.vscode/settings.json` بـ `"files.encoding": "utf8"` لمساعدة Cursor/VS Code على الحفظ الصحيح.

### أوامر سريعة (نسخة من جذر `orange`)

```text
scripts\verify-php-utf8.ps1
scripts\verify-php-utf8.ps1 -Fix
scripts\install-hooks.ps1
```

### UTF-16 conversion batch (deploy reference)

`includes/catalog_schema.php`, `includes/catalog_labels.php`, `admin/pages/color_dictionary.php`, `admin/pages/size_families.php`, `admin/api/channels/save.php`, `admin/api/colors/save.php`, `admin/api/departments/translate.php`, `admin/api/expenses/delete.php`, `admin/api/expenses/remove.php`, `admin/api/expenses/update.php`, `admin/api/journal/manage.php`, `admin/api/orders/update.php`, `admin/api/purchases/update.php`, `admin/api/size_families/save.php`, `admin/api/size_families/save_sizes.php` (and previously `admin/api/_shared/translator.php`, later **deleted**).

---

## Catalog / DB

- `orange_catalog_safe_exec`; `SET NAMES` at start of `orange_catalog_ensure_schema`; `$done = true` only at **end** of migration.

---

## Colors / sizes UI

- Department-style `data-*-json`, `JSON_UNESCAPED_UNICODE`, `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`, delegated clicks, stable `tbody` ids.

---

## Translation

- Do **not** restore `admin/api/_shared/translator.php`. Use `admin/api/translate/names.php` + `admin/api/lib/translate_names_lib.php`.

---

## Invoice

- `inv_test` deleted. `invoice.php` improved (search, recent list). Keep `invoice` in `$allowed`.

---

## User expectations for future AI sessions

1. Verify **UTF-8 / not UTF-16** before finishing PHP with Arabic.
2. Do not say "no context in chat" without reading **`IBRAHIM_ORANGE_MASTER.txt`**, **`docs/archive/ORANGE_PROJECT_CONTINUITY.txt`**, and **`.cursor/rules/orange-php-utf8-workflow.mdc`** (this `.md` file is archive detail only).
3. **`config.php`:** user policy is **do not modify** for helpers/features unless they explicitly ask. Order helpers (`require_fields`, `generate_order_number`) are in **`includes/order_helpers.php`**, not `config.php`.

**Project path (canonical):** `D:\orange` — see `IBRAHIM_ORANGE_MASTER.txt` and `docs/archive/ORANGE_PROJECT_CONTINUITY.txt` §8. **Agents:** do not infer workspace location from old chat paths; only `D:\orange` is documented as canonical.

---

## ملخص بالعربي

### ماذا حدث؟
- مشاكل عربي / PHP خام بعد **الرفع**، بينما **اللصق اليدوي** في الاستضافة يصلح العرض.
- السبب: ملفات PHP كانت **UTF-16** على القرص؛ السيرفر يتوقع **UTF-8**.
- **ممنوع** (بطلب المستخدم) الاعتماد على `MYSQL_ATTR_INIT_COMMAND` في `config.php`؛ الترميز مع MySQL عبر `SET NAMES` داخل `catalog_schema`.
- **سياسة المستخدم:** لا تعديل `config.php` لإضافة دوال مساعدة؛ دوال الطلبات `require_fields` و`generate_order_number` في **`includes/order_helpers.php`**.
- `inv_test` أُزيل؛ الفاتورة تُحسَّن عند فتحها بدون طلب محدد.
- **الجداول الإرشادية (مكتبة):** التفاصيل في **`docs/archive/ORANGE_ADVISORY_SIZING_LIBRARY_DECISION.md`**؛ ترحيل **031** + صفحة **«مكتبة أدلة المقاسات»**: تسلسل **قسم → قالب مقاسات → نوع تجاري 1 → عائلة مصدر** ثم تصميم الأدلة وفتح الدليل بـ `size_family_id`؛ ربط عائلة مستهلك + مزامنة مع تحقق القالب والنوع التجاري. التجاوز على المنتج لاحقاً.

### أين تُثبَّت طريقة العمل؟
1. **`IBRAHIM_ORANGE_MASTER.txt`** — دخول موحّد لأي جلسة جديدة.
2. `.cursor/rules/orange-php-utf8-workflow.mdc` — قاعدة Cursor دائمة.
3. `docs/archive/ORANGE_PROJECT_CONTINUITY.txt` — مؤشر المشروع والمسار.
4. `.cursor/rules/orange-continuity.mdc` — يوجّه لقراءة الملفات أعلاه.
5. `docs/archive/ORANGE_CHAT_CONTINUITY.md` — أرشيف تقني وتفاصيل نشر/ترميز.
6. `docs/archive/ORANGE_ADVISORY_SIZING_LIBRARY_DECISION.md` — **قرار تصميم مستقبلي:** مكتبة أدلة مقاسات + ربط عائلات مقاسات + إرث للمنتج (موثّق 2026-05-10).

### رفع الملفات بدون كسر الترميز (تلخيص)
- **الأفضل:** `git push` من الجهاز ثم `git pull` على السيرفر — **ممنوع** لصق PHP في محرر الاستضافة كعادة يومية.
- قبل الـ commit: شغّل `scripts\verify-php-utf8.ps1`؛ لو في خطأ شغّل `-Fix` ثم أعد الفحص.
- مرة واحدة: `scripts\install-hooks.ps1` عشان الـ pre-commit يمسك الـ BOM قبل الرفع.

**ملاحظة:** الوكيل لا يحفظ المحادثة تلقائياً بين الجلسات؛ لذلك وُضعت هذه الملفات **داخل المشروع** كمرجع دائم.

---

## Update 2026-06-18 — Delivery Fee Policy + OTP Discussion (Archive)

### Owner discussion context
- هدف النقاش: تسهيل إدارة **قيمة التوصيل** في الأدمن، وتحديد سياسة واضحة:  
  1) مجاني للكل،  
  2) مجاني للمسجلين فقط،  
  3) أو رسوم على الكل.
- طُرح سؤال تنفيذي: هل يمكن **تسجيل دخول تلقائي** فقط لأن رقم الهاتف موجود مسبقاً؟

### Agreed points (owner + agent discussion)
- تعريف المسجل المفضل في النقاش الحالي: **الخيار 1** = عميل مسجل دخول فعليًا بحساب storefront موثّق.
- **لا يُنصح** بعمل auto-login تلقائي اعتمادًا على وجود رقم الهاتف فقط (مخاطرة انتحال رقم).
- تحقق OTP على **الهاتف** (SMS/WhatsApp) ليس مجانيًا عادةً؛ يحتاج مزود إرسال خارجي مدفوع.
- التحقق “بدون رسوم إضافية غالبًا” يكون عبر **الإيميل** (OTP/verify flow) وليس عبر الهاتف.

### Policy direction discussed for delivery fee
- الاتجاه الأنسب تشغيليًا: **mixed policy**  
  - سياسة عامة على مستوى الدولة،  
  - مع استثناءات على مستوى المحافظة عند الحاجة،  
  - مع إمكانية override على مستوى المنطقة.
- ملاحظة تصميمية: وجود قيمة على مستوى المحافظة يساعد كـ default لتقليل إدخال نفس القيمة لكل منطقة يدويًا.

### Technical note captured for next implementation step
- قبل التنفيذ الفعلي لسياسة “مجاني للمسجلين”، يجب توحيد تعريف “registered buyer” بين:
  - `api/cart/checkout-preview.php` (حساب المعاينة),
  - `includes/order_intake_queue.php` (الحساب النهائي للطلب),
  حتى لا يظهر فرق بين السعر في المعاينة والسعر عند تأكيد الطلب.

### Status
- هذا التحديث **توثيق نقاش واتفاقات أولية** فقط.
- التنفيذ البرمجي مؤجل لحين قرار المالك النهائي على نطاق السياسة التفصيلي.

---

## Update 2026-06-18 — Full Session Log (including delivery fee)

### A) What was executed and pushed today
- إزالة صفحة مردودات المبيعات المنفصلة بعد الدمج داخل `sales_reports`:
  - حذف `admin/pages/sales_returns_report.php`
  - تنظيف الربط من `admin/index.php` و`admin/partials/header.php` و`includes/admin_nav_tree.php` و`includes/admin_permissions.php`
  - تحويل رابط `channel_analytics` إلى `sales_reports&r=returns`
  - push على `main` بمرجع: `2a090495`.
- تعديل ترتيب مجموعة القائمة في «المبيعات والعروض»:
  - رأس المجموعة أصبح «تقارير المبيعات»
  - الترتيب: `تحليل المبيعات` ثم `تحليل القنوات` ثم `تقارير المبيعات`
  - تعديل في `admin/partials/header.php` و`includes/admin_nav_tree.php`
  - push على `main` بمرجع: `9cecbe36`.

### B) Delivery fee discussion (detailed capture)
- المطلوب التشغيلي من المالك: تسهيل إدخال قيمة التوصيل (bulk/default) مع إمكانية التطبيق على نطاق أوسع بدل التكرار اليدوي.
- تم تثبيت تفضيل النطاق في النقاش:
  - التطبيق على **كل مناطق الدولة الحالية**.
  - وتخزين نفس القيمة كـ **default للمناطق الجديدة** لاحقًا.
- في نقاش سياسة الرسوم على المتجر، طُرحت السيناريوهات:
  1) مجاني للكل
  2) مجاني للمسجلين فقط
  3) الرسوم على الكل
- تم اختيار اتجاه السياسات كهيكل: **mixed**
  - سياسة دولة عامة
  - مع استثناء محافظة
  - وإمكانية override على المنطقة عند الحاجة.
- بالنسبة لتعريف «المسجل» في النقاش:
  - المالك فضّل **الخيار 1**: العميل المسجل دخول فعليًا.
- ملاحظة تقنية مهمة مثبتة للنقاش:
  - يلزم توحيد منطق احتساب/أهلية الرسوم بين
    `api/cart/checkout-preview.php` و`includes/order_intake_queue.php`
    حتى لا يحدث فرق بين المعاينة والتنفيذ.

### C) OTP + auto-login discussion (detailed capture)
- سؤال المالك: هل يمكن auto-login للعميل بمجرد إدخال رقم هاتف مسجل؟
- الخلاصة المؤرشفة:
  - لا يُنصح أمنيًا بعمل auto-login اعتمادًا على رقم الهاتف فقط.
  - التحقق الهاتفي OTP (SMS/WhatsApp) غالبًا **ليس مجانيًا** لأنه يعتمد على مزود إرسال خارجي.
  - البديل الأقل تكلفة: تحقق عبر البريد الإلكتروني (OTP/verify link) مع تسجيل الدخول بعد التحقق.
- تم توثيق أن قاعدة البيانات وحدها تكفي للتخزين/المقارنة، لكن لا تغني عن قناة إرسال موثوقة للكود.

### D) Current status for continuation
- البنود أعلاه توثيق نقاش + قرارات اتجاهية.
- لا يوجد تنفيذ برمجي جديد لهذه السياسة حتى قرار المالك النهائي على تفاصيل التطبيق.

---

## Update 2026-06-18 — OTP Continuation Addendum (Full capture)

### E) Additional clarifications requested by owner
- تم طلب توضيح عملي: هل OTP مجاني وأوتوماتيكي بالكامل؟
  - التوضيح المؤرشف: OTP للهاتف (SMS/WhatsApp) ليس مجانيًا غالبًا، بينما الإيميل هو المسار الأقل تكلفة/الأقرب للمجاني ضمن إعدادات البريد.
- تم طلب توضيح دقيق لمسار التسجيل الحالي بدون تخمين:
  - المراجعة تمت على `pages/register.php` + `api/auth/request-email-verify.php` + `pages/verify-email.php` + `includes/storefront_account.php`.
  - الخلاصة: الإيميل **لا يصبح موثقًا عند إرسال الرابط**؛ التوثيق يتم فقط عند فتح رابط التأكيد (الذي ينفّذ `email_verified_at = NOW()`).

### F) Security-hardening direction (agreed for next implementation)
- تم الاتفاق الاتجاهي على تقوية الحماية ضد الاستيلاء بالآتي:
  1. OTP checkout يُرسل فقط إلى البريد المرتبط بحساب موثق لنفس الهاتف.
  2. اعتماد سياسة صارمة **Session-only** لاعتبار العميل “مسجّل”.
  3. منع أي `storefront_account_id` قادم من payload من التأثير على ربط الطلب.
  4. أي تعارض هاتف/إيميل أو نقل ملكية بيانات يبقى عبر مسار merge المعتمد، وليس عبر OTP checkout.
  5. واجهة checkout تتضمن خيار **تجاهل تسجيل الدخول** حتى لا يُعطّل إتمام الطلب كضيف.

### G) UX flow confirmed in discussion
- إذا الهاتف مرتبط بحساب موثق:
  - تظهر رسالة: تم إرسال OTP إلى بريد masked.
  - تظهر رسالة تحفيزية لدخول الحساب للاستفادة من الخصومات.
  - يظهر إدخال OTP + تحقق + إعادة إرسال + تجاهل تسجيل الدخول.
- بعد التحقق الناجح:
  - يتم تسجيل الدخول للجلسة مباشرة.
  - يجب إعادة حساب preview فورًا.
  - يجب أن يتطابق منطق “المسجل” بين `checkout-preview` والتنفيذ النهائي للطلب.

### H) Status
- هذا القسم إضافة توثيقية مكملة لنفس جلسة النقاش.
- التنفيذ البرمجي ما زال مؤجلًا لقرار المالك ببدء التنفيذ الفعلي.

---

## Update 2026-06-18 — Final Auth Direction Agreed (owner)

### I) Agreed login model (two paths)
- تم الاتفاق على نموذج دخول مزدوج للواجهة:
  1) **Quick login عبر OTP على الإيميل** (عند الحاجة كمسار سريع).
  2) **Login تقليدي**: `username = email` و`password` يختاره العميل عند التسجيل.

### J) Password recovery agreement
- عند نسيان كلمة المرور:
  - يُرسل OTP/رابط تحقق إلى الإيميل،
  - ثم يتم تعيين كلمة مرور جديدة،
  - ثم تسجيل الدخول للموقع.

### K) Security constraints (must hold with the new model)
- استمرار سياسة الحماية الصارمة ضد الاستيلاء:
  - اعتبار العميل “مسجّل” فقط عند وجود **جلسة موثقة فعليًا**.
  - عدم السماح بربط حساب من payload (`storefront_account_id`) مباشرة.
  - مسارات merge/ربط الهاتف المعتمدة تبقى المرجع عند تعارض الهاتف/الإيميل.
- النتيجة المطلوبة: لا اختلاف في أهلية الخصومات بين المعاينة والتنفيذ النهائي للطلب.

### L) Status
- هذه إضافة أرشيفية لقرار الاتجاه النهائي في النقاش.
- التنفيذ البرمجي لم يبدأ بعد (بانتظار توجيه المالك ببدء التنفيذ).

---

## Update 2026-06-18 — Delivery Policy Card + OTP Checkout (Implemented)

### M) What was implemented in code
- **Schema + shared helpers**:
  - `includes/catalog_schema.php`: رفع `ORANGE_CATALOG_SCHEMA_PHP_REVISION` إلى `90` وإضافة migration `orange_catalog_migrate_delivery_policy_checkout_otp_v90` لأعمدة سياسة التوصيل (`countries`) وحقول OTP (`storefront_accounts`).
  - `includes/delivery_areas.php`: helpers لقراءة/حفظ سياسة الدولة، التطبيق الجماعي على المناطق النشطة، وحسم قيمة التوصيل الموحّد حسب حالة تسجيل الجلسة.
  - `includes/storefront_account.php`: helpers للحساب الموثّق فقط + hash/clear/check لحقول OTP checkout.
- **Admin delivery policy card**:
  - `admin/pages/delivery_areas.php`: كارت أعلى الصفحة (قيمة افتراضية + 3 خيارات حصرية + حفظ + حفظ وتطبيق على المناطق النشطة).
  - `admin/api/delivery_areas/manage.php`: actions `get_policy` و`save_policy` داخل transaction، واعتماد default fee عند إنشاء منطقة جديدة بدون قيمة.
- **Session-only unification (preview/create/order intake)**:
  - `api/orders/create-order.php`: تجاهل `storefront_account_id` و`_buyer_registered` من payload والاعتماد على جلسة `current_storefront_account`.
  - `api/cart/checkout-preview.php` + `includes/order_intake_queue.php`: استخدام helper موحّد لحسم قيمة التوصيل وربط أهلية «المسجّل» بالجلسة فقط لضمان تطابق الإجمالي بين المعاينة والتنفيذ.
- **OTP APIs (manual send)**:
  - جديد: `api/auth/request-checkout-email-otp.php` و`api/auth/verify-checkout-email-otp.php`.
  - دعم cooldown + expiry + max attempts + hash + phone binding + login session بعد تحقق OTP.
- **Checkout UI wiring**:
  - `pages/cart.php`: إضافة بلوك OTP (إرسال يدوي، إعادة إرسال، إدخال الكود، تحقق، تجاهل تسجيل الدخول).
  - `assets/js/cart.js`: ربط UI مع APIs + إدارة cooldown/feedback + إعادة حساب preview مباشرة بعد نجاح OTP.
- **Translations**:
  - `config.php`: إضافة مفاتيح ترجمة OTP الجديدة (ar/en/fil/hi) لرسائل الواجهة والأزرار والأخطاء.

### N) Verification notes (quick smoke scope)
- منطق الخيارات الثلاثة في سياسة التوصيل أصبح حصرياً (radio) ومربوطاً مباشرةً بحسم قيمة التوصيل.
- مسار OTP أصبح يمر عبر إرسال يدوي فقط، مع تبريد ومحاولات وحدّ انتهاء صلاحية.
- بعد نجاح OTP، الجلسة تُعتبر logged-in فوراً وتُعاد معاينة السلة لإظهار مزايا المسجّل بنفس قاعدة تنفيذ الطلب.
