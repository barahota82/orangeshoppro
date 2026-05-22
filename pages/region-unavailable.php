<?php

declare(strict_types=1);

$lang = orange_lang_from_accept_language_header($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
if ($lang !== 'ar') {
    $lang = 'en';
}

$title = $lang === 'ar' ? 'الخدمة غير متاحة في منطقتك' : 'Service not available in your region';
$body = $lang === 'ar'
    ? 'عذراً، المتجر غير متاح تلقائياً من موقعك الحالي. إذا كان لديك رابط مباشر لقناة المتجر فيمكنك فتحه للدخول.'
    : 'Sorry, the store is not automatically available from your current location. If you have a direct store channel link, you can use it to enter.';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang, ENT_QUOTES, 'UTF-8'); ?>" dir="<?php echo $lang === 'ar' ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            padding: 1.5rem;
        }
        .card {
            max-width: 28rem;
            width: 100%;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1.75rem 1.5rem;
            text-align: center;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }
        h1 {
            margin: 0 0 0.75rem;
            font-size: 1.25rem;
            line-height: 1.4;
            color: #0f172a;
        }
        p {
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.6;
            color: #475569;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
</body>
</html>
