<?php
use HubOS\Core\Permissions;

/**
 * @var array<string, mixed> $user
 * @var int|null $clientsCount
 * @var int|null $eventsCount
 * @var list<array<string, mixed>> $modules
 * @var list<array<string, mixed>> $events
 */
$actionLabels = [
    'tenant.created'     => 'חברה הוקמה',
    'user.created'       => 'משתמש נוצר',
    'client.created'     => 'לקוח נוצר',
    'auth.login'         => 'כניסה למערכת',
    'auth.logout'        => 'יציאה מהמערכת',
    'permission.granted' => 'הרשאה ניתנה',
    'permission.revoked' => 'הרשאה נשללה',
    'permission.cleared' => 'הרשאה אופסה',
    'module.enabled'     => 'מודול הופעל',
    'module.disabled'    => 'מודול כובה',
];
$moduleLabels = ['clients' => 'לקוחות'];
?>
<section class="hero card" aria-labelledby="hero-title">
    <div>
        <p class="eyebrow">OS HUB v2</p>
        <h2 id="hero-title">שלום, <?= e($user['name']) ?></h2>
        <p class="muted">השלד החדש פעיל: התחברות מאובטחת, הפרדת לקוחות, הרשאות ויומן אירועים שאי אפשר למחוק.</p>
    </div>
    <div class="hero-glow" aria-hidden="true"></div>
</section>

<section class="stats" aria-label="נתונים מרכזיים">
    <article class="stat card">
        <span class="stat-label">לקוחות</span>
        <strong class="stat-value" id="stat-clients"><?= $clientsCount === null ? '—' : e($clientsCount) ?></strong>
        <a class="stat-link" href="/clients">לרשימה ←</a>
    </article>
    <article class="stat card">
        <span class="stat-label">אירועים ביומן</span>
        <strong class="stat-value" id="stat-events"><?= $eventsCount === null ? '—' : e($eventsCount) ?></strong>
        <span class="stat-hint">append-only</span>
    </article>
    <article class="stat card">
        <span class="stat-label">מודולים פעילים</span>
        <strong class="stat-value"><?= e(count($modules)) ?></strong>
        <span class="stat-hint">
            <?= e(implode(' · ', array_map(static fn (array $m): string => $moduleLabels[$m['module']] ?? (string) $m['module'], $modules))) ?>
        </span>
    </article>
    <article class="stat card">
        <span class="stat-label">התפקיד שלך</span>
        <strong class="stat-value stat-value-sm"><?= e(Permissions::ROLE_LABELS[$user['role']] ?? $user['role']) ?></strong>
        <span class="stat-hint"><?= e($user['tenant_name']) ?></span>
    </article>
</section>

<section class="card" aria-labelledby="events-title">
    <div class="card-head">
        <h2 id="events-title">פעילות אחרונה</h2>
    </div>
    <?php if ($events === []): ?>
        <p class="empty">אין אירועים להצגה.</p>
    <?php else: ?>
        <ol class="timeline">
            <?php foreach ($events as $ev): ?>
                <li class="timeline-item">
                    <span class="dot" aria-hidden="true"></span>
                    <div>
                        <strong><?= e($actionLabels[$ev['action']] ?? $ev['action']) ?></strong>
                        <span class="muted">· <?= e($ev['actor_name'] ?? 'מערכת') ?></span>
                    </div>
                    <time class="muted" datetime="<?= e($ev['created_at']) ?>"><?= e(fmt_dt($ev['created_at'])) ?></time>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
