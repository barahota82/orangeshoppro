<?php

declare(strict_types=1);

/**
 * يولّد password_hash متوافق مع admin/login.php (PASSWORD_DEFAULT / bcrypt).
 *
 * الاستخدام من مجلد المشروع:
 *   php scripts/generate_password_hash.php "كلمة_السر_الجديدة"
 *
 * ثم في MySQL:
 *   UPDATE admins SET password_hash = 'الصق_الناتج_هنا' WHERE username = 'اسم_المستخدم' LIMIT 1;
 *
 * بديل واجهة الويب (للمشرف العام فقط): /admin/index.php?page=password_hash_tool
 *
 * لا ترفع هذا الملف لمسار عام على الويب؛ للاستخدام المحلي أو SSH فقط.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$pass = $argv[1] ?? '';
if ($pass === '') {
    fwrite(STDERR, "Usage: php scripts/generate_password_hash.php \"your_new_password\"\n");
    exit(1);
}

echo password_hash($pass, PASSWORD_DEFAULT) . PHP_EOL;
