<?php
/**
 * @var string $content
 * @var string $appName
 * @var string|null $title
 */
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?> · <?= e($appName) ?></title>
    <meta name="description" content="OS HUB — מערכת ההפעלה של האב מערכות מתקדמות: לקוחות, גבייה, שעות ופרויקטים במקום אחד.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <link rel="preload" href="/assets/fonts/heebo-hebrew.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="/assets/app.css?v=13">
</head>
<body class="auth-body">
    <div class="aurora" aria-hidden="true"><span></span><span></span><span></span></div>
    <main class="auth-shell">
        <?= $content ?>
    </main>
</body>
</html>
