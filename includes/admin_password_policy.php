<?php

declare(strict_types=1);

function orange_admin_password_min_length(): int
{
    return 12;
}

function orange_admin_password_generate_length(): int
{
    return 16;
}

function orange_admin_password_special_chars(): string
{
    return '!@#$%^&*()_-+=?';
}

function orange_admin_password_policy_hint_ar(): string
{
    $min = orange_admin_password_min_length();
    $sym = orange_admin_password_special_chars();

    return 'كلمة المرور: ' . $min . ' حرفاً على الأقل، حرف كبير (A-Z)، حرف صغير (a-z)، رقم، رمز (' . $sym . ')، بدون تسلسلات مثل 123 أو 111، ومختلفة عن اسم الدخول. يمكنك الكتابة يدوياً أو «توليد باسورد قوي».';
}

/**
 * @return list<string>
 */
function orange_admin_password_common_weak_list(): array
{
    return [
        'password',
        'password1',
        'password123',
        'admin',
        'admin123',
        'administrator',
        '123456',
        '12345678',
        '123456789',
        '1234567890',
        'qwerty',
        'qwerty123',
        'letmein',
        'welcome',
        'orange',
        'orange123',
        'changeme',
        'master',
        'root',
        'test',
        'test123',
    ];
}

function orange_admin_password_is_ascending_digit_triplet(string $triplet): bool
{
    if (strlen($triplet) < 3 || !ctype_digit($triplet)) {
        return false;
    }
    for ($i = 1; $i < strlen($triplet); $i++) {
        if ((int) $triplet[$i] !== (int) $triplet[$i - 1] + 1) {
            return false;
        }
    }

    return true;
}

function orange_admin_password_is_descending_digit_triplet(string $triplet): bool
{
    if (strlen($triplet) < 3 || !ctype_digit($triplet)) {
        return false;
    }
    for ($i = 1; $i < strlen($triplet); $i++) {
        if ((int) $triplet[$i] !== (int) $triplet[$i - 1] - 1) {
            return false;
        }
    }

    return true;
}

function orange_admin_password_has_bad_digit_patterns(string $password): bool
{
    if (!preg_match_all('/\d+/', $password, $matches)) {
        return false;
    }
    if (empty($matches[0])) {
        return false;
    }
    foreach ($matches[0] as $run) {
        $runLen = strlen($run);
        if ($runLen >= 3 && preg_match('/^(\d)\1+$/', $run)) {
            return true;
        }
        if ($runLen < 3) {
            continue;
        }
        for ($i = 0; $i <= $runLen - 3; $i++) {
            $triplet = substr($run, $i, 3);
            if (orange_admin_password_is_ascending_digit_triplet($triplet)
                || orange_admin_password_is_descending_digit_triplet($triplet)) {
                return true;
            }
            if (preg_match('/^(\d)\1\1$/', $triplet)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return string|null رسالة خطأ عربية أو null إذا مقبول
 */
function orange_admin_password_validate(string $password, string $username = ''): ?string
{
    $min = orange_admin_password_min_length();
    if ($password === '') {
        return 'كلمة المرور مطلوبة';
    }
    if ($password !== trim($password)) {
        return 'كلمة المرور لا يجب أن تبدأ أو تنتهي بمسافة';
    }
    $len = strlen($password);
    if ($len < $min) {
        return 'كلمة المرور قصيرة — الحد الأدنى ' . $min . ' حرفاً';
    }
    if ($len > 128) {
        return 'كلمة المرور طويلة جداً (128 حرفاً كحد أقصى)';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'يجب أن تحتوي كلمة المرور على حرف كبير (Capital) واحد على الأقل';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'يجب أن تحتوي كلمة المرور على حرف صغير (Small) واحد على الأقل';
    }
    if (!preg_match('/\d/', $password)) {
        return 'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل';
    }
    $specialQuoted = preg_quote(orange_admin_password_special_chars(), '/');
    if (!preg_match('/[' . $specialQuoted . ']/', $password)) {
        return 'يجب أن تحتوي كلمة المرور على رمز خاص واحد على الأقل (' . orange_admin_password_special_chars() . ')';
    }
    if (preg_match('/^(.)\1+$/u', $password)) {
        return 'كلمة المرور لا يمكن أن تكون مكررة بنفس الحرف';
    }
    $usernameNorm = strtolower(trim($username));
    if ($usernameNorm !== '' && strtolower($password) === $usernameNorm) {
        return 'كلمة المرور يجب أن تختلف عن اسم الدخول';
    }
    $lower = strtolower($password);
    if (in_array($lower, orange_admin_password_common_weak_list(), true)) {
        return 'كلمة المرور ضعيفة أو شائعة — اختر كلمة أقوى';
    }
    if (orange_admin_password_has_bad_digit_patterns($password)) {
        return 'تجنّب تسلسلات الأرقام (مثل 123 أو 987) أو تكرار نفس الرقم (111)';
    }

    return null;
}

/**
 * توليد باسورد عشوائي يحقق السياسة (للأدمن — اختياري).
 */
function orange_admin_password_generate(string $username = '', int $length = 0): string
{
    $length = $length > 0 ? $length : orange_admin_password_generate_length();
    $length = max(orange_admin_password_min_length(), min(64, $length));

    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghjkmnpqrstuvwxyz';
    $digits = '23456789';
    $special = orange_admin_password_special_chars();
    $all = $upper . $lower . $digits . $special;

    for ($attempt = 0; $attempt < 80; $attempt++) {
        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $special[random_int(0, strlen($special) - 1)],
        ];
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);
        $candidate = implode('', $chars);
        if (orange_admin_password_validate($candidate, $username) === null) {
            return $candidate;
        }
    }

    return 'K9#mPx2$vLqN8@wR';
}
