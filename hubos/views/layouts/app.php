<?php
use HubOS\Core\Csrf;
use HubOS\Core\Permissions;
use HubOS\Core\Str;

/**
 * @var string $content
 * @var string $appName
 * @var array<string, mixed> $user
 * @var string|null $flash
 * @var \HubOS\Core\Context $ctx
 */
$active = $active ?? '';
$nav = [
    ['key' => 'dashboard', 'href' => '/dashboard', 'label' => 'לוח בקרה', 'icon' => '◆', 'perm' => 'dashboard.view'],
    ['key' => 'clients',   'href' => '/clients',   'label' => 'לקוחות',   'icon' => '◎', 'perm' => 'clients.view'],
];
$soon = ['גבייה', 'שעות', 'פרויקטים', 'משימות', 'מכירות', 'נכסים וכספת', 'דוחות'];
?>
<!doctype html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?> · <?= e($appName) ?></title>
    <meta name="description" content="OS HUB — מערכת ההפעלה של האב מערכות מתקדמות: לקוחות, גבייה, שעות ופרויקטים במקום אחד.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;700;800&amp;display=swap">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="app-body">
<div class="app-shell">
    <aside class="sidebar" aria-label="ניווט ראשי">
        <a class="brand" href="/dashboard" id="nav-brand">
            <span class="brand-mark" aria-hidden="true">OS</span>
            <span class="brand-text"><strong>OS HUB</strong><small><?= e($user['tenant_name'] ?? '') ?></small></span>
        </a>
        <nav class="nav">
            <?php foreach ($nav as $item): ?>
                <?php if ($ctx->can($item['perm'])): ?>
                    <a id="nav-<?= e($item['key']) ?>" href="<?= e($item['href']) ?>"
                       class="nav-link<?= $active === $item['key'] ? ' is-active' : '' ?>"
                       <?= $active === $item['key'] ? 'aria-current="page"' : '' ?>>
                        <span class="nav-icon" aria-hidden="true"><?= e($item['icon']) ?></span><?= e($item['label']) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
            <p class="nav-section">בקרוב</p>
            <?php foreach ($soon as $label): ?>
                <span class="nav-link is-soon" aria-disabled="true"><span class="nav-icon" aria-hidden="true">·</span><?= e($label) ?></span>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <span class="phase-pill">שלב 0 · שלד</span>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <h1 class="page-title"><?= e($title ?? '') ?></h1>
            <div class="user-chip">
                <span class="avatar" aria-hidden="true"><?= e(Str::first((string) ($user['name'] ?? '?'))) ?></span>
                <span class="user-meta">
                    <strong><?= e($user['name'] ?? '') ?></strong>
                    <small><?= e(Permissions::ROLE_LABELS[$user['role'] ?? ''] ?? '') ?></small>
                </span>
                <form method="post" action="/logout" class="logout-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm" id="logout-button">יציאה</button>
                </form>
            </div>
        </header>

        <main class="content" id="main-content">
            <?php if (!empty($flash)): ?>
                <div class="alert alert-success" role="status"><?= e($flash) ?></div>
            <?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>
</body>
</html>
