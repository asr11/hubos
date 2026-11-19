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
$openTasks = $portal['open_tasks'] ?? [];
$totalTasksCount = count($tasks);
$doneTasksCount = count(array_filter($tasks, static fn (array $t): bool => TaskService::statusKey((string) ($t['status'] ?? '')) === 'done'));
$overallPct = $totalTasksCount > 0 ? (int) round(($doneTasksCount / $totalTasksCount) * 100) : 0;
$validSslCount = count(array_filter($assets, static fn (array $a): bool => ($a['ssl_status'] ?? '') === 'valid'));

$widthBucket = static function (int $pct): string {
    return match (true) {
        $pct >= 100 => 'w-100',
        $pct >= 75  => 'w-75',
        $pct >= 60  => 'w-67',
        $pct >= 45  => 'w-50',
        $pct >= 28  => 'w-33',
        $pct >= 10  => 'w-25',
        default     => 'w-0',
    };
};
?>
<section class="hero card" id="client-portal-hero" aria-labelledby="hero-title">
    <div>
        <p class="eyebrow">OS HUB · פורטל לקוח אישי (שקיפות מלאה בזמן אמת)</p>
        <h2 id="hero-title">שלום, <?= e($user['name']) ?></h2>
        <?php if ($client !== null): ?>
            <p class="muted" id="portal-client-name">תיק לקוח: <strong><?= e($client['name']) ?></strong> · מעקב שקוף אחר חבילת השירות, התקדמות המשימות, תקינות האתר והנכסים הדיגיטליים.</p>
            <div class="portal-hero-actions">
                <a class="btn btn-primary btn-sm" id="portal-report-link" href="/clients/<?= e((int) $client['id']) ?>/report">📄 הפקת דוח סטטוס והתקדמות (PDF / הדפסה)</a>
                <a class="btn btn-ghost btn-sm" href="/clients/<?= e((int) $client['id']) ?>">פתיחת תיק לקוח מלא ←</a>
            </div>
        <?php else: ?>
            <p class="muted">המשתמש אינו משויך ללקוח פעיל.</p>
        <?php endif; ?>
    </div>
    <div class="hero-glow" aria-hidden="true"></div>
</section>

<?php if ($client !== null): ?>
<section class="stats" aria-label="נתוני פורטל לקוח">
    <article class="stat card">
        <span class="stat-label">התקדמות משימות</span>
        <strong class="stat-value" id="portal-stat-tasks"><?= e($doneTasksCount) ?> / <?= e($totalTasksCount) ?></strong>
        <span class="stat-hint">הושלמו (<b dir="ltr"><?= e($overallPct) ?>%</b>) · בטיפול: <?= e(count($openTasks)) ?></span>
    </article>
    <article class="stat card">
        <span class="stat-label">מסלול שירות / בנק שעות</span>
        <strong class="stat-value" id="portal-stat-bank" dir="ltr">
            <?= ($metrics['bank_remaining'] ?? null) === null ? 'ריטיינר' : e(number_format((float) $metrics['bank_remaining'], 1)) . 'h' ?>
        </strong>
        <span class="stat-hint"><?= count($banks) > 0 ? e(count($banks)) . ' בנקי שעות פעילים' : 'שירות חודשי מנוהל' ?></span>
    </article>
    <article class="stat card">
        <span class="stat-label">שעות מדווחות</span>
        <strong class="stat-value" id="portal-stat-hours" dir="ltr"><?= e(number_format((float) ($metrics['hours_this_month'] ?? 0), 1)) ?>h</strong>
        <span class="stat-hint">סה״כ מצטבר: <b dir="ltr"><?= e(number_format((float) ($metrics['hours_total'] ?? 0), 1)) ?>h</b></span>
    </article>
    <article class="stat card">
        <span class="stat-label">נכסים ואבטחת SSL</span>
        <strong class="stat-value" id="portal-stat-assets"><?= e(count($assets)) ?></strong>
        <span class="stat-hint"><?= $validSslCount > 0 ? '🔒 ' . e($validSslCount) . ' מאומתים בתעודת SSL תקינה' : 'משויכים לתיק הלקוח' ?></span>
    </article>
</section>

<?php if ($projects !== []): ?>
<section class="card" id="portal-services-scope" aria-labelledby="portal-scope-title">
    <div class="card-head">
        <h2 id="portal-scope-title">על מה משלמים · חבילות שירות פעילות והתקדמות (<?= e(count($projects)) ?>)</h2>
        <span class="pill pill-ok">מעודכן בזמן אמת</span>
    </div>
    <div class="stack">
    <?php foreach ($projects as $p):
        $pid = (int) ($p['id'] ?? 0);
        $pTasks = array_values(array_filter($tasks, static fn (array $t): bool => (int) ($t['project_id'] ?? 0) === $pid));
        $pTotal = count($pTasks);
        $pDone = count(array_filter($pTasks, static fn (array $t): bool => TaskService::statusKey((string) ($t['status'] ?? '')) === 'done'));
        $pPct = $pTotal > 0 ? (int) round(($pDone / $pTotal) * 100) : ($overallPct);
    ?>
        <article class="card">
            <div class="card-head">
                <div>
                    <strong><?= fmt_inline_bidi((string) ($p['name'] ?? '')) ?></strong>
                    <?php if (!empty($p['description'])): ?>
                        <div class="muted mt-sm"><?= fmt_rich_text((string) $p['description']) ?></div>
                    <?php endif; ?>
                </div>
                <span class="pill pill-ok" dir="ltr"><?= e($pPct) ?>%</span>
            </div>
            <div class="project-progress-wrap" aria-label="התקדמות חבילת השירות">
                <div class="project-progress-track">
                    <span class="project-progress-fill <?= e($widthBucket($pPct)) ?>"></span>
                </div>
                <span class="muted">הושלמו <?= e($pDone) ?> מתוך <?= e($pTotal) ?> אבני דרך גלויות</span>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="portal-tasks-title">
    <div class="card-head">
        <h2 id="portal-tasks-title">פירוט משימות, אבני דרך והתקדמות (<?= e(count($tasks)) ?>)</h2>
    </div>
    <?php if ($tasks === []): ?>
        <p class="empty">אין משימות גלויות להצגה כעת.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" id="portal-tasks-table">
                <thead>
                    <tr>
                        <th scope="col">משימה ופירוט ביצוע</th>
                        <th scope="col">חבילה / פרויקט</th>
                        <th scope="col">סטטוס</th>
                        <th scope="col">תאריך יעד</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $t): $st = TaskService::statusKey((string) $t['status']); ?>
                    <tr>
                        <td>
                            <a class="client-link" href="/tasks/<?= e((int) $t['id']) ?>"><strong><?= fmt_inline_bidi((string) $t['title']) ?></strong></a>
                            <?php if (!empty($t['description'])): ?>
                                <div class="muted"><?= fmt_inline_bidi((string) $t['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($t['project_name'] ?? '—') ?></td>
                        <td><span class="tstatus tstatus-<?= e($st) ?>"><?= e(TaskService::STATUSES[$st] ?? $t['status']) ?></span></td>
                        <td dir="ltr"><?= e($t['due_at'] ? fmt_date((string) $t['due_at']) : '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="grid-2-wide">
    <article class="card" aria-labelledby="portal-assets-title">
        <div class="card-head">
            <h2 id="portal-assets-title">נכסים, דומיינים ואבטחת SSL (<?= e(count($assets)) ?>)</h2>
        </div>
        <?php if ($assets === []): ?>
            <p class="empty">אין נכסים רשומים לתיק זה.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="portal-assets-table">
                    <thead>
                        <tr>
                            <th scope="col">דומיין / נכס</th>
                            <th scope="col">ספק / תשתית</th>
                            <th scope="col">אבטחת SSL</th>
                            <th scope="col">תוקף דומיין</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($assets as $a):
                        $sslOk = ($a['ssl_status'] ?? '') === 'valid';
                    ?>
                        <tr>
                            <td dir="ltr"><strong><?= e($a['domain'] ?? '—') ?></strong></td>
                            <td><bdi><?= e($a['provider'] ?? '—') ?></bdi></td>
                            <td>
                                <?php if ($sslOk): ?>
                                    <span class="pill pill-ok">🔒 תקין<?= !empty($a['ssl_expiry_date']) ? ' עד ' . e(fmt_date((string) $a['ssl_expiry_date'])) : '' ?></span>
                                <?php else: ?>
                                    <span class="pill pill-warn"><?= e(AssetService::SSL_STATUSES[$a['ssl_status'] ?? ''] ?? 'בבדיקה') ?></span>
                                <?php endif; ?>
                            </td>
                            <td dir="ltr"><?= e(fmt_date($a['expiry_date'] ?? null) ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <article class="card" aria-labelledby="portal-banks-title">
        <div class="card-head">
            <h2 id="portal-banks-title">בנקי שעות ומסלול ליווי (<?= e(count($banks)) ?>)</h2>
        </div>
        <?php if ($banks === []): ?>
            <p class="muted">התיק מנוהל במסלול פרויקט / ריטיינר חודשי קבוע (אחסון, אבטחה וקידום שוטף) ללא הגבלת בנק שעות נפרד. ניתן להפיק דוח התקדמות מלא בלחיצה על כפתור ה-PDF למעלה.</p>
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
                        <td><strong><?= fmt_inline_bidi((string) $b['name']) ?></strong></td>
                        <td dir="ltr"><?= e(number_format((float) $b['allowance_hours'], 1)) ?>h</td>
                        <td dir="ltr"><?= e(number_format((float) $b['used_hours'], 1)) ?>h (<?= e((int) $b['pct']) ?>%)</td>
                        <td dir="ltr"><strong><?= e(number_format((float) $b['remaining_hours'], 1)) ?>h</strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </article>
</section>
<?php endif; ?>
