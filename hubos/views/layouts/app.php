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
    ['key' => 'dashboard', 'href' => '/dashboard',      'label' => 'לוח בקרה',        'icon' => '◆', 'perm' => 'dashboard.view'],
    ['key' => 'clients',   'href' => '/clients',        'label' => 'לקוחות',          'icon' => '◎', 'perm' => 'clients.view'],
    ['key' => 'time',      'href' => '/time',           'label' => 'שעות',            'icon' => '◷', 'perm' => 'time.view'],
    ['key' => 'tasks',     'href' => '/tasks',          'label' => 'משימות',          'icon' => '☑', 'perm' => 'work.view'],
    ['key' => 'projects',  'href' => '/projects',       'label' => 'פרויקטים',        'icon' => '▣', 'perm' => 'work.view'],
    ['key' => 'linking',   'href' => '/work/linking',   'label' => 'ממתין לשיוך',     'icon' => '⇄', 'perm' => 'work.link'],
    ['key' => 'recurring', 'href' => '/work/recurring', 'label' => 'תחזוקה חודשית',   'icon' => '↻', 'perm' => 'work.create'],
    ['key' => 'assets',    'href' => '/assets',         'label' => 'נכסים ודומיינים', 'icon' => '🌐', 'perm' => 'assets.view'],
    ['key' => 'vault',     'href' => '/vault',          'label' => 'כספת גישות',      'icon' => '🔒', 'perm' => 'vault.view'],
    ['key' => 'users',     'href' => '/settings/users', 'label' => 'משתמשים והרשאות', 'icon' => '⚙', 'perm' => 'users.view'],
];
$soon = [];
$timerBox = $timerBox ?? null;
$returnTo = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$returnTo = preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&%.\#]*$#', $returnTo) === 1 ? $returnTo : '/';
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
    <script src="/assets/timer.js?v=2" defer></script>
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
        </nav>
        <div class="sidebar-foot">
            <span class="phase-pill">M6 · הפעלה מלאה</span>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <h1 class="page-title"><?= e($title ?? '') ?></h1>
            <?php if ($timerBox !== null): $tm = $timerBox['timer']; ?>
            <div class="timer-box<?= $tm !== null ? ' is-active' : '' ?>" id="timer-box" data-state-url="/timer" aria-label="טיימר">
                <?php if ($tm !== null): ?>
                    <span class="timer-dot <?= $tm['running'] ? 'is-running' : 'is-paused' ?>" aria-hidden="true"></span>
                    <span class="timer-what">
                        <a href="/clients/<?= e((int) $tm['client_id']) ?>" id="timer-client"><?= e($tm['client_name'] ?? '') ?></a>
                        <?php if (!empty($tm['task_title'])): ?><small class="muted" id="timer-task"><?= e($tm['task_title']) ?></small><?php endif; ?>
                    </span>
                    <time id="timer-elapsed" class="timer-elapsed" data-elapsed="<?= e((int) $tm['elapsed_seconds']) ?>" data-running="<?= $tm['running'] ? '1' : '0' ?>"><?= e(sprintf('%d:%02d:%02d', intdiv((int) $tm['elapsed_seconds'], 3600), intdiv((int) $tm['elapsed_seconds'] % 3600, 60), (int) $tm['elapsed_seconds'] % 60)) ?></time>
                    <form method="post" action="/timer/<?= $tm['running'] ? 'pause' : 'resume' ?>" class="inline-form">
                        <?= Csrf::field() ?><input type="hidden" name="_return" value="<?= e($returnTo) ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" id="timer-<?= $tm['running'] ? 'pause' : 'resume' ?>"><?= $tm['running'] ? '⏸ השהיה' : '▶ המשך' ?></button>
                    </form>
                    <form method="post" action="/timer/stop" class="inline-form timer-stop-form" id="timer-stop-form">
                        <?= Csrf::field() ?><input type="hidden" name="_return" value="<?= e($returnTo) ?>">
                        <input type="text" name="description" id="timer-description" placeholder="מה עשית? (חובה)" required minlength="2" maxlength="500" aria-label="תיאור העבודה">
                        <button type="submit" class="btn btn-primary btn-sm" id="timer-stop">⏹ עצירה</button>
                    </form>
                    <form method="post" action="/timer/discard" class="inline-form">
                        <?= Csrf::field() ?><input type="hidden" name="_return" value="<?= e($returnTo) ?>">
                        <button type="submit" class="btn btn-ghost btn-sm" id="timer-discard" title="ביטול ללא רישום">✕</button>
                    </form>
                <?php else: ?>
                    <form method="post" action="/timer/start" class="inline-form timer-start-form" id="timer-start-form">
                        <?= Csrf::field() ?><input type="hidden" name="_return" value="<?= e($returnTo) ?>">
                        <select name="client_id" id="timer-client-select" data-role="client-select" required aria-label="לקוח">
                            <option value="">לקוח…</option>
                            <?php foreach ($timerBox['clients'] as $tc): ?><option value="<?= e((int) $tc['id']) ?>"><?= e($tc['name']) ?></option><?php endforeach; ?>
                        </select>
                        <select name="task_id" id="timer-task-select" data-role="task-select" aria-label="משימה (רשות)">
                            <option value="">משימה (רשות)</option>
                            <?php foreach ($timerBox['tasks'] as $tt): ?><option value="<?= e((int) $tt['id']) ?>" data-client="<?= e((int) $tt['client_id']) ?>"><?= e($tt['title']) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm" id="timer-start">▶ טיימר</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
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
                <div class="alert alert-success" role="status" id="flash"><?= e($flash) ?></div>
            <?php endif; ?>
            <?php if (!empty($flashError)): ?>
                <div class="alert alert-danger" role="alert" id="flash-error"><?= e($flashError) ?></div>
            <?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>
</body>
</html>
