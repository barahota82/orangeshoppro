# Orange project - conversation continuity and technical decisions

> **IBRAHIM — للمحادثات الجديدة:** ابدأ من **`IBRAHIM_ORANGE_MASTER.txt`** في جذر المشروع (ورقة دخول موحّدة + تسلسل أسبقية الوثائق). هذا الملف (`ORANGE_CHAT_CONTINUITY.md`) يبقى **أرشيفاً تقنياً** (ترميز، نشر، فاتورة، قرارات قديمة). المسار المعتمد للمشروع: **D:\orange** — انظر `ORANGE_PROJECT_CONTINUITY.txt` §8.

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
2. Do not say "no context in chat" without reading **`IBRAHIM_ORANGE_MASTER.txt`**, **`ORANGE_PROJECT_CONTINUITY.txt`**, and **`.cursor/rules/orange-php-utf8-workflow.mdc`** (this `.md` file is archive detail only).
3. **`config.php`:** user policy is **do not modify** for helpers/features unless they explicitly ask. Order helpers (`require_fields`, `generate_order_number`) are in **`includes/order_helpers.php`**, not `config.php`.

**Project path (canonical):** `D:\orange` — see `IBRAHIM_ORANGE_MASTER.txt` and `ORANGE_PROJECT_CONTINUITY.txt` §8 (do not rely on older Desktop-only notes).

---

## ملخص بالعربي

### ماذا حدث؟
- مشاكل عربي / PHP خام بعد **الرفع**، بينما **اللصق اليدوي** في الاستضافة يصلح العرض.
- السبب: ملفات PHP كانت **UTF-16** على القرص؛ السيرفر يتوقع **UTF-8**.
- **ممنوع** (بطلب المستخدم) الاعتماد على `MYSQL_ATTR_INIT_COMMAND` في `config.php`؛ الترميز مع MySQL عبر `SET NAMES` داخل `catalog_schema`.
- **سياسة المستخدم:** لا تعديل `config.php` لإضافة دوال مساعدة؛ دوال الطلبات `require_fields` و`generate_order_number` في **`includes/order_helpers.php`**.
- `inv_test` أُزيل؛ الفاتورة تُحسَّن عند فتحها بدون طلب محدد.

### أين تُثبَّت طريقة العمل؟
1. **`IBRAHIM_ORANGE_MASTER.txt`** — دخول موحّد لأي جلسة جديدة.
2. `.cursor/rules/orange-php-utf8-workflow.mdc` — قاعدة Cursor دائمة.
3. `ORANGE_PROJECT_CONTINUITY.txt` — مؤشر المشروع والمسار.
4. `.cursor/rules/orange-continuity.mdc` — يوجّه لقراءة الملفات أعلاه.
5. هذا الملف `ORANGE_CHAT_CONTINUITY.md` — أرشيف تقني وتفاصيل نشر/ترميز.

### رفع الملفات بدون كسر الترميز (تلخيص)
- **الأفضل:** `git push` من الجهاز ثم `git pull` على السيرفر — **ممنوع** لصق PHP في محرر الاستضافة كعادة يومية.
- قبل الـ commit: شغّل `scripts\verify-php-utf8.ps1`؛ لو في خطأ شغّل `-Fix` ثم أعد الفحص.
- مرة واحدة: `scripts\install-hooks.ps1` عشان الـ pre-commit يمسك الـ BOM قبل الرفع.

**ملاحظة:** الوكيل لا يحفظ المحادثة تلقائياً بين الجلسات؛ لذلك وُضعت هذه الملفات **داخل المشروع** كمرجع دائم.
