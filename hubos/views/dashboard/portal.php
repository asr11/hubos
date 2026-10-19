<?php
use HubOS\Modules\Assets\AssetService;
use HubOS\Modules\Work\TaskService;

/**
 * @var array<string, mixed> $user
 * @var array<string, mixed> $portal
 */
$client = $portal['client'] ?? null;
$tasks = $portal['tasks'] ?? [];
$projects = $portal['projects'] ?? [];
$banks = $portal['hours_banks'] ?? [];
$assets = $portal['assets'] ?? [];
$metrics = $portal['metrics'] ?? [];
?>
<section class="hero card" id="client-portal-hero" aria-labelledby="hero-title">
    <div>
        <p class="eyebrow">OS HUB · פורטל לקוח (צפייה בלבד)</p>
        <h2 id="hero-title">שלום, <?= e($user['name']) ?></h2>
        <?php if ($client !== null): ?>
            <p class="muted" id="portal-client-name">תיק לקוח: <strong><?= e($client['name']) ?></strong> · צפייה שקופה במשימות פתוחות, יתרת בנק שעות ונכסים דיגיטליים.</p>
        <?php else: ?>
            <p class="muted">המשתמש אינו משויך ללקוח פעיל.</p>
        <?php endif; ?>
    </div>
    <div class="hero-glow" aria-hidden="true"></div>
</section>

<?php if ($client !== null): ?>
<section class="stats" aria-label="נתוני פורטל לקוח">
    <article class="stat card">
        <span class="stat-label">משימות גלויות ללקוח</span>
        <strong class="stat-value" id="portal-stat-tasks"><?= e(count($tasks)) ?></strong>
        <span class="stat-hint">פתוחות: <?= e(count($portal['open_tasks'] ?? [])) ?></span>
    </article>
    <article class="stat card">
        <span class="stat-label">שעות החודש / מצטבר</span>
        <strong class="stat-value" id="portal-stat-hours" dir="ltr"><?= e(number_format((float) ($metrics['hours_this_month'] ?? 0), 1)) ?>h</strong>
        <span class="stat-hint">סה״כ: <b dir="ltr"><?= e(number_format((float) ($metrics['hours_total'] ?? 0), 1)) ?>h</b></span>
    </article>
    <article class="stat card">
        <span class="stat-label">יתרת בנק שעות</span>
        <strong class="stat-value" id="portal-stat-bank" dir="ltr">
            <?= ($metrics['bank_remaining'] ?? null) === null ? '—' : e(number_format((float) $metrics['bank_remaining'], 1)) . 'h' ?>
        </strong>
        <span class="stat-hint"><?= e(count($banks)) ?> בנקי שעות</span>
    </article>
    <article class="stat card">
        <span class="stat-label">נכסים ודומיינים</span>
        <strong class="stat-value" id="portal-stat-assets"><?= e(count($assets)) ?></strong>
        <span class="stat-hint">משויכים לתיק הלקוח</span>
    </article>
</section>

<section class="card" aria-labelledby="portal-tasks-title">
    <div class="card-head">
        <h2 id="portal-tasks-title">משימות ופרויקטים בטיפול (<?= e(count($tasks)) ?>)</h2>
    </div>
    <?php if ($tasks === []): ?>
        <p class="empty">אין משימות גלויות להצגה כעת.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" id="portal-tasks-table">
                <thead>
                    <tr>
                        <th scope="col">משימה</th>
                        <th scope="col">פרויקט</th>
                        <th scope="col">סטטוס</th>
                        <th scope="col">תאריך יעד</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t): $st = TaskService::statusKey((string) $t['status']); ?>
                    <tr>
                        <td><strong><?= e($t['title']) ?></strong></td>
                        <td><?= e($t['project_name'] ?? '—') ?></td>
                        <td><span class="pill"><?= e(TaskService::STATUSES[$st] ?? $t['status']) ?></span></td>
                        <td dir="ltr"><?= e($t['due_at'] ? substr((string) $t['due_at'], 0, 10) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1rem">
    <article class="card" aria-labelledby="portal-banks-title">
        <div class="card-head">
            <h2 id="portal-banks-title">בנקי שעות (<?= e(count($banks)) ?>)</h2>
        </div>
        <?php if ($banks === []): ?>
            <p class="empty">אין בנקי שעות פעילים לתיק זה.</p>
        <?php else: ?>
            <table class="table" id="portal-banks-table">
                <thead>
                    <tr>
                        <th scope="col">שם בנק</th>
                        <th scope="col">הקצאה</th>
                        <th scope="col">נוצל</th>
                        <th scope="col">יתרה</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($banks as $b): ?>
                    <tr>
                        <td><strong><?= e($b['name']) ?></strong></td>
                        <td dir="ltr"><?= e(number_format((float) $b['allowance_hours'], 1)) ?>h</td>
                        <td dir="ltr"><?= e(number_format((float) $b['used_hours'], 1)) ?>h (<?= e((int) $b['pct']) ?>%)</td>
                        <td dir="ltr"><strong><?= e(number_format((float) $b['remaining_hours'], 1)) ?>h</strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>

    <article class="card" aria-labelledby="portal-assets-title">
        <div class="card-head">
            <h2 id="portal-assets-title">נכסים ודומיינים (<?= e(count($assets)) ?>)</h2>
        </div>
        <?php if ($assets === []): ?>
            <p class="empty">אין נכסים רשומים לתיק זה.</p>
        <?php else: ?>
            <table class="table" id="portal-assets-table">
                <thead>
                    <tr>
                        <th scope="col">סוג</th>
                        <th scope="col">דומיין / מזהה</th>
                        <th scope="col">ספק</th>
                        <th scope="col">תוקף</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($assets as $a): ?>
                    <tr>
                        <td><?= e(AssetService::KINDS[$a['kind'] ?? 'other'] ?? $a['kind']) ?></td>
                        <td dir="ltr"><strong><?= e($a['domain'] ?? '—') ?></strong></td>
                        <td><?= e($a['provider'] ?? '—') ?></td>
                        <td dir="ltr"><?= e($a['expiry_date'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>
</section>
<?php endif; ?>
