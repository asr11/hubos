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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;700;800&amp;display=swap">
    <link rel="stylesheet" href="/assets/app.css?v=2">
</head>
<body class="auth-body">
    <div class="aurora" aria-hidden="true"><span></span><span></span><span></span></div>
    <main class="auth-shell">
        <?= $content ?>
    </main>
</body>
</html>
