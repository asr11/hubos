<?php
use HubOS\Modules\Assets\AssetService;
use HubOS\Modules\Clients\ClientSeoService;
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
$seoKeywords = $portal['seo_keywords'] ?? [];
$metrics = $portal['metrics'] ?? [];
$openTasks = $portal['open_tasks'] ?? [];

$doneTasks = [];
$doingTasks = [];
$todoTasks = [];
foreach ($tasks as $t) {
    $st = TaskService::statusKey((string) ($t['status'] ?? 'todo'));
    if ($st === 'done') {
        $doneTasks[] = $t;
    } elseif ($st === 'doing') {
        $doingTasks[] = $t;
    } else {
        $todoTasks[] = $t;
    }
}
$totalTasksCount = count($tasks);
$doneTasksCount = count($doneTasks);
$overallPct = $totalTasksCount > 0 ? (int) round(($doneTasksCount / $totalTasksCount) * 100) : 0;
$validSslCount = count(array_filter($assets, static fn (array $a): bool => ($a['ssl_status'] ?? '') === 'valid'));
$hoursTotal = (float) ($metrics['hours_total'] ?? 0.0);
$hoursThisMonth = (float) ($metrics['hours_this_month'] ?? 0.0);
$showSeoKpi = $banks === [] && $hoursTotal <= 0.0 && $seoKeywords !== [];

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
            <p class="muted" id="portal-client-name">תיק לקוח: <strong><?= e($client['name']) ?></strong> · מעקב שקוף אחר חבילת השירות, התקדמות המשימות, מילות המפתח בגוגל ותקינות האתר.</p>
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
        <span class="stat-label">התקדמות אבני דרך</span>
        <strong class="stat-value" id="portal-stat-tasks" dir="ltr"><?= e($doneTasksCount) ?> / <?= e($totalTasksCount) ?></strong>
        <span class="stat-hint">הושלמו (<b dir="ltr"><?= e($overallPct) ?>%</b>) · פעילות: <?= e(count($openTasks)) ?></span>
    </article>
    <article class="stat card">
        <span class="stat-label">מסלול שירות / בנק שעות</span>
        <strong class="stat-value" id="portal-stat-bank" dir="ltr">
            <?= ($metrics['bank_remaining'] ?? null) === null ? 'ריטיינר' : e(number_format((float) $metrics['bank_remaining'], 1)) . 'h' ?>
        </strong>
        <span class="stat-hint"><?= count($banks) > 0 ? e(count($banks)) . ' בנקי שעות פעילים' : 'שירות חודשי מנוהל' ?></span>
    </article>
    <?php if ($showSeoKpi): ?>
    <article class="stat card">
        <span class="stat-label">מילות מפתח בקידום (SEO &amp; GEO)</span>
        <strong class="stat-value" id="portal-stat-hours"><?= e(count($seoKeywords)) ?></strong>
        <span class="stat-hint">ביטויי מפתח במעקב ואופטימיזציה</span>
    </article>
    <?php else: ?>
    <article class="stat card">
        <span class="stat-label">שעות מדווחות</span>
        <strong class="stat-value" id="portal-stat-hours" dir="ltr"><?= e(number_format($hoursThisMonth, 1)) ?>h</strong>
        <span class="stat-hint">סה״כ מצטבר: <b dir="ltr"><?= e(number_format($hoursTotal, 1)) ?>h</b></span>
    </article>
    <?php endif; ?>
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
        $pPct = $pTotal > 0 ? (int) round(($pDone / $pTotal) * 100) : $overallPct;
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
                <span class="muted">הושלמו <?= e($pDone) ?> מתוך <?= e($pTotal) ?> אבני דרך</span>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="portal-roadmap-grid" id="portal-roadmap" aria-label="מפת התקדמות: מה עשינו, מה עושים ומה נעשה">
    <article class="roadmap-col roadmap-col-done">
        <div class="roadmap-col-head">
            <h3>✅ מה עשינו (הושלם)</h3>
            <span class="pill pill-ok"><?= e(count($doneTasks)) ?></span>
        </div>
        <?php if ($doneTasks === []): ?>
            <p class="empty">טרם סומנו אבני דרך שהושלמו.</p>
        <?php else: ?>
            <ul class="roadmap-list">
            <?php foreach ($doneTasks as $t): ?>
                <li class="roadmap-item">
                    <a class="roadmap-item-title" href="/tasks/<?= e((int) $t['id']) ?>"><?= fmt_inline_bidi((string) $t['title']) ?></a>
                    <?php if (!empty($t['description'])): ?>
                        <p class="roadmap-item-desc"><?= fmt_inline_bidi((string) $t['description']) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>

    <article class="roadmap-col roadmap-col-doing">
        <div class="roadmap-col-head">
            <h3>⚡ מה עושים עכשיו (בביצוע)</h3>
            <span class="pill pill-warn"><?= e(count($doingTasks)) ?></span>
        </div>
        <?php if ($doingTasks === []): ?>
            <p class="empty">אין משימות פתוחות בביצוע כרגע.</p>
        <?php else: ?>
            <ul class="roadmap-list">
            <?php foreach ($doingTasks as $t): ?>
                <li class="roadmap-item">
                    <a class="roadmap-item-title" href="/tasks/<?= e((int) $t['id']) ?>"><?= fmt_inline_bidi((string) $t['title']) ?></a>
                    <?php if (!empty($t['description'])): ?>
                        <p class="roadmap-item-desc"><?= fmt_inline_bidi((string) $t['description']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($t['due_at'])): ?>
                        <div class="roadmap-item-meta">יעד חודשי: <b dir="ltr"><?= e(fmt_date((string) $t['due_at'])) ?></b></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>

    <article class="roadmap-col roadmap-col-todo">
        <div class="roadmap-col-head">
            <h3>📅 מה נעשה בהמשך (מתוכנן)</h3>
            <span class="pill"><?= e(count($todoTasks)) ?></span>
        </div>
        <?php if ($todoTasks === []): ?>
            <p class="empty">אין משימות עתידיות מתוכננות כרגע.</p>
        <?php else: ?>
            <ul class="roadmap-list">
            <?php foreach ($todoTasks as $t): ?>
                <li class="roadmap-item">
                    <a class="roadmap-item-title" href="/tasks/<?= e((int) $t['id']) ?>"><?= fmt_inline_bidi((string) $t['title']) ?></a>
                    <?php if (!empty($t['description'])): ?>
                        <p class="roadmap-item-desc"><?= fmt_inline_bidi((string) $t['description']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($t['due_at'])): ?>
                        <div class="roadmap-item-meta">מתוכנן ל: <b dir="ltr"><?= e(fmt_date((string) $t['due_at'])) ?></b></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>
</section>

<?php if ($seoKeywords !== []): ?>
<section class="card" id="portal-seo-keywords" aria-labelledby="portal-seo-title">
    <div class="card-head">
        <h2 id="portal-seo-title">🔍 מילות מפתח מרכזיות בקידום אורגני — Google SEO &amp; AI Search (<?= e(count($seoKeywords)) ?>)</h2>
        <span class="muted">ביטויים ממוקדים בעמודי האתר הפעילים</span>
    </div>
    <div class="table-wrap">
        <table class="table" id="portal-seo-table">
            <thead>
                <tr>
                    <th scope="col">מילת מפתח / ביטוי יעד</th>
                    <th scope="col">עמוד יעד באתר</th>
                    <th scope="col">שלב קידום</th>
                    <th scope="col">מיקום מאומת בגוגל</th>
                    <th scope="col">פעולת קידום</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($seoKeywords as $kw):
                $stage = (string) ($kw['stage'] ?? 'optimizing');
                $rank = $kw['current_rank'] !== null ? (int) $kw['current_rank'] : null;
                $prev = $kw['previous_rank'] !== null ? (int) $kw['previous_rank'] : null;
                $url = (string) ($kw['target_url'] ?? '');
                $urlLabel = $url !== '' ? urldecode((string) preg_replace('#^https?://#i', '', rtrim($url, '/'))) : '—';
            ?>
                <tr>
                    <td><strong><?= e($kw['keyword']) ?></strong></td>
                    <td dir="ltr">
                        <?php if ($url !== ''): ?>
                            <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($urlLabel) ?> ↗</a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="pill kw-stage-<?= e($stage) ?>"><?= e(ClientSeoService::STAGES[$stage] ?? $stage) ?></span></td>
                    <td>
                        <?php if ($rank !== null): ?>
                            <strong dir="ltr">#<?= e($rank) ?></strong>
                            <?php if ($prev !== null && $prev > $rank): ?>
                                <span class="pill pill-ok" dir="ltr">▲ +<?= e($prev - $rank) ?></span>
                            <?php elseif ($prev !== null && $prev < $rank): ?>
                                <span class="pill pill-warn" dir="ltr">▼ -<?= e($rank - $prev) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">במדידה שוטפת</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= e($kw['notes'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

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
            <p class="muted">התיק מנוהל במסלול פרויקט / ריטיינר חודשי קבוע (אחסון, אבטחה וקידום אורגני שוטף) ללא הגבלת בנק שעות נפרד. ניתן להפיק דוח התקדמות מלא בלחיצה על כפתור ה-PDF למעלה.</p>
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
