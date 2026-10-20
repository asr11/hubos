<?php
use HubOS\Core\Permissions;
use HubOS\Modules\Assets\AssetService;
use HubOS\Modules\Work\TaskService;

/**
 * @var array<string, mixed> $user
 * @var int|null $clientsCount
 * @var int|null $eventsCount
 * @var list<array<string, mixed>> $modules
 * @var list<array<string, mixed>> $events
 * @var array<string, mixed> $urgent
 * @var array<string, mixed> $breaking
 * @var array<string, mixed> $money
 * @var array<string, mixed> $team
 */
$actionLabels = [
    'tenant.created'                => 'חברה הוקמה',
    'user.created'                  => 'משתמש נוצר',
    'user.updated'                  => 'משתמש עודכן',
    'client.created'                => 'לקוח נוצר',
    'client.registry_applied'       => 'פרטי רשם החברות הוחלו',
    'auth.login'                    => 'כניסה למערכת',
    'auth.logout'                   => 'יציאה מהמערכת',
    'permission.granted'            => 'הרשאה ניתנה',
    'permission.revoked'            => 'הרשאה נשללה',
    'permission.cleared'            => 'הרשאה אופסה',
    'module.enabled'                => 'מודול הופעל',
    'module.disabled'               => 'מודול כובה',
    'time_entry.created'            => 'שעות דווחו',
    'time_entry.updated'            => 'דיווח שעות עודכן',
    'time_entry.deleted'            => 'דיווח שעות נמחק',
    'timer.started'                 => 'טיימר הופעל',
    'timer.paused'                  => 'טיימר הושהה',
    'timer.resumed'                 => 'טיימר חודש',
    'timer.stopped'                 => 'טיימר נעצר ונרשם',
    'timer.discarded'               => 'טיימר בוטל',
    'hours_bank.threshold_crossed'  => 'חציית סף בנק שעות',
    'hours_bank.settings_updated'   => 'הגדרות בנק שעות עודכנו',
    'task.created'                  => 'משימה נוצרה',
    'task.updated'                  => 'משימה עודכנה',
    'task.archived'                 => 'משימה הועברה לארכיון',
    'task.restored'                 => 'משימה שוחזרה מארכיון',
    'task.client_linked'            => 'משימה שויכה ללקוח',
    'project.created'               => 'פרויקט נוצר',
    'project.updated'               => 'פרויקט עודכן',
    'project.archived'              => 'פרויקט הועבר לארכיון',
    'project.client_linked'         => 'פרויקט שויך ללקוח',
    'recurring_template.created'    => 'תבנית תחזוקה נוצרה',
    'recurring.generated'           => 'משימת תחזוקה חודשית נוצרה',
    'asset.created'                 => 'נכס נוצר',
    'asset.updated'                 => 'נכס עודכן',
    'asset.inspected'               => 'בדיקת DNS/SSL/WHOIS בוצעה',
    'vault.item_created'            => 'רשומת כספת נוצרה',
    'vault.item_updated'            => 'רשומת כספת עודכנה',
    'vault.secret_revealed'         => 'סוד נחשף בכספת',
    'vault.metadata_viewed'         => 'צפייה ברשימת כספת',
    'sync.completed'                => 'סנכרון ואימות נתונים הושלם',
    'import.completed'              => 'ייבוא נתונים הושלם',
];
$moduleLabels = Permissions::SWITCHABLE_MODULES;
$overdueCount = count($urgent['overdue_tasks'] ?? []);
$highCount = count($urgent['high_tasks'] ?? []);
$needsLinking = (int) ($urgent['needs_linking_total'] ?? 0);
$openDemands = $urgent['open_demands'] ?? ['count' => 0, 'amount' => 0.0, 'top_clients' => [], 'recent' => []];
$assetKpis = $breaking['asset_kpis'] ?? ['total' => 0, 'expired' => 0, 'expiring_30d' => 0, 'ssl_issues' => 0];
$breakingAssets = $breaking['assets'] ?? [];
$alertBanks = $breaking['banks'] ?? [];
$sep = $money['separation'] ?? [];
$prov = $provenance ?? null;
?>
<section class="hero card" aria-labelledby="hero-title">
    <div>
        <p class="eyebrow">OS HUB v2 · מסך הבוקר של המנכ״ל (60 שניות)</p>
        <h2 id="hero-title">שלום, <?= e($user['name']) ?></h2>
        <p class="muted">תמונת מצב חיה ומאומתת: מה דחוף היום, מה הולך להישבר, כמה כסף ממומש מול דרישות תשלום פתוחות, ומה הצוות ביצע.</p>
    </div>
    <div class="hero-glow" aria-hidden="true"></div>
</section>

<?php if (is_array($prov)): ?>
<section class="card" id="provenance-banner" aria-label="אימות מקור נתונים וחותמת זמן" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem;padding:0.85rem 1.25rem;border-right:4px solid var(--ok, #16a34a)">
    <div class="cluster" style="gap:1.25rem;flex-wrap:wrap">
        <span class="pill pill-ok" id="prov-verified">✔ מקור נתונים מאומת</span>
        <span><strong>מקור:</strong> <bdi dir="ltr" id="prov-source"><?= e((string) ($prov['source_label'] ?? 'Cloudflare D1 (hub-os-d1)')) ?></bdi></span>
        <span><strong>תאריך וזמן שליפה מדויק:</strong> <bdi dir="ltr" id="prov-timestamp"><?= e((string) ($prov['fetched_at_il'] ?? '')) ?></bdi> <span class="muted small">(<?= e((string) ($prov['relative_age'] ?? '')) ?>)</span></span>
        <span><strong>חתימת אימות (SHA-256):</strong> <code dir="ltr" id="prov-sha256" title="<?= e((string) ($prov['source_sha256'] ?? '')) ?>"><?= e((string) ($prov['sha256_short'] ?? '')) ?></code></span>
    </div>
    <form method="post" action="/settings/sync-d1" style="margin:0">
        <?= \HubOS\Core\Csrf::field() ?>
        <input type="hidden" name="_return" value="/dashboard">
        <button type="submit" class="btn btn-sm" id="btn-sync-d1">סנכרון ואימות עכשיו ↻</button>
    </form>
</section>
<?php endif; ?>

<section class="stats" aria-label="נתונים מרכזיים">
    <article class="stat card">
        <span class="stat-label">לקוחות פעילים</span>
        <strong class="stat-value" id="stat-clients"><?= $clientsActiveCount !== null ? e($clientsActiveCount) : ($clientsCount === null ? '—' : e($clientsCount)) ?></strong>
        <span class="stat-hint">מתוך <?= e($clientsCount ?? 0) ?> רשומות במערכת</span>
        <a class="stat-link" href="/clients">לרשימת הלקוחות ←</a>
    </article>
    <?php if (!empty($canFinance)): ?>
    <article class="stat card">
        <span class="stat-label">הכנסה ממומשת (<?= e($money['year'] ?? '') ?>)</span>
        <strong class="stat-value" id="stat-income-year" dir="ltr">₪<?= e(number_format((float) ($money['income_this_year'] ?? 0), 0)) ?></strong>
        <span class="stat-hint">סה״כ רב-שנתי: <b dir="ltr">₪<?= e(number_format((float) ($money['income_total'] ?? 0), 0)) ?></b></span>
    </article>
    <article class="stat card">
        <span class="stat-label">דרישות תשלום פתוחות (300)</span>
        <strong class="stat-value" id="stat-open-demands" dir="ltr">₪<?= e(number_format((float) ($openDemands['amount'] ?? 0), 0)) ?></strong>
        <span class="stat-hint"><?= e((int) ($openDemands['count'] ?? 0)) ?> חשבונות עסקה ללא קבלה/חשבונית מס</span>
    </article>
    <?php endif; ?>
    <article class="stat card">
        <span class="stat-label">אירועים ביומן</span>
        <strong class="stat-value" id="stat-events"><?= $eventsCount === null ? '—' : e($eventsCount) ?></strong>
        <span class="stat-hint"><?= e(Permissions::ROLE_LABELS[$user['role']] ?? $user['role']) ?> · <?= e(count($modules)) ?> מודולים</span>
    </article>
</section>

<!-- ===================================================================== -->
<!-- 1. מה דחוף היום (Urgent Today)                                        -->
<!-- ===================================================================== -->
<section class="card" id="sec-urgent" aria-labelledby="urgent-title">
    <div class="card-head">
        <div>
            <p class="eyebrow">1 · סדר עדיפויות מיידי</p>
            <h2 id="urgent-title">מה דחוף היום</h2>
        </div>
        <div class="cluster">
            <span class="pill <?= $overdueCount > 0 ? 'pill-danger' : 'pill-ok' ?>">באיחור: <?= e($overdueCount) ?></span>
            <span class="pill <?= $highCount > 0 ? 'pill-warn' : 'pill-ok' ?>">עדיפות גבוהה/דחופה: <?= e($highCount) ?></span>
            <?php if ($needsLinking > 0): ?>
                <a class="pill pill-warn" href="/work/linking">ממתין לשיוך לקוח: <?= e($needsLinking) ?> ←</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1rem">
        <div>
            <h3>משימות דחופות ובאיחור</h3>
            <?php $urgentTasks = array_slice(array_values(array_unique(array_merge($urgent['overdue_tasks'] ?? [], $urgent['high_tasks'] ?? []), SORT_REGULAR)), 0, 10); ?>
            <?php if ($urgentTasks === []): ?>
                <p class="empty">אין משימות באיחור או בעדיפות דחופה.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table" id="urgent-tasks-table">
                        <thead>
                            <tr>
                                <th scope="col">משימה</th>
                                <th scope="col">לקוח</th>
                                <th scope="col">עדיפות</th>
                                <th scope="col">יעד</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($urgentTasks as $t): ?>
                            <tr>
                                <td><a href="/tasks/<?= e((int) $t['id']) ?>"><strong><?= e($t['title']) ?></strong></a></td>
                                <td>
                                    <?php if (!empty($t['client_id'])): ?>
                                        <a href="/clients/<?= e((int) $t['client_id']) ?>"><?= e($t['client_name'] ?? ('#' . (int) $t['client_id'])) ?></a>
                                    <?php else: ?>
                                        <a href="/work/linking" class="pill pill-warn">ללא שיוך</a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="pill <?= ($t['priority'] ?? '') === 'urgent' ? 'pill-danger' : 'pill-warn' ?>">
                                        <?= e(TaskService::PRIORITIES[$t['priority'] ?? 'normal'] ?? $t['priority']) ?>
                                    </span>
                                </td>
                                <td dir="ltr"><?= e($t['due_at'] ? substr((string) $t['due_at'], 0, 10) : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($canFinance)): ?>
        <div>
            <h3>מועמדים לגבייה · דרישות תשלום פתוחות (חשבון עסקה 300)</h3>
            <p class="muted" style="margin-bottom:.5rem">
                חשבונות עסקה (300) אינם הכנסה ממומשת. כאן מוצגים חשבונות עסקה שטרם נסגרו מול חשבונית מס (305), חשבונית מס/קבלה (320/330) או קבלה (400).
            </p>
            <?php if (($openDemands['top_clients'] ?? []) === []): ?>
                <p class="empty">אין דרישות תשלום פתוחות.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table" id="urgent-demands-table">
                        <thead>
                            <tr>
                                <th scope="col">לקוח</th>
                                <th scope="col">מסמכי 300 פתוחים</th>
                                <th scope="col">סכום פתוח לגבייה</th>
                                <th scope="col">תאריך אחרון</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($openDemands['top_clients'] as $odClient): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($odClient['client_id'])): ?>
                                        <a href="/clients/<?= e((int) $odClient['client_id']) ?>?tab=collection"><strong><?= e($odClient['client_name']) ?></strong></a>
                                    <?php else: ?>
                                        <strong><?= e($odClient['client_name']) ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((int) $odClient['docs_count']) ?></td>
                                <td dir="ltr"><strong>₪<?= e(number_format((float) $odClient['total_amount'], 0)) ?></strong></td>
                                <td dir="ltr" class="muted"><?= e($odClient['latest_date'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ===================================================================== -->
<!-- 2. מה הולך להישבר (What's About to Break)                             -->
<!-- ===================================================================== -->
<section class="card" id="sec-breaking" aria-labelledby="breaking-title">
    <div class="card-head">
        <div>
            <p class="eyebrow">2 · מניעת תקלות ונטישה</p>
            <h2 id="breaking-title">מה הולך להישבר (דומיינים, SSL ובנקי שעות)</h2>
        </div>
        <div class="cluster">
            <a class="pill <?= $assetKpis['expired'] > 0 ? 'pill-danger' : 'pill-ok' ?>" href="/assets?window=expired">פגי תוקף: <?= e($assetKpis['expired']) ?></a>
            <a class="pill <?= $assetKpis['expiring_30d'] > 0 ? 'pill-warn' : 'pill-ok' ?>" href="/assets?window=30d">מתחדשים ב-30 יום: <?= e($assetKpis['expiring_30d']) ?></a>
            <span class="pill <?= count($alertBanks) > 0 ? 'pill-danger' : 'pill-ok' ?>">בנקי שעות בחריגה/התראה: <?= e(count($alertBanks)) ?></span>
        </div>
    </div>

    <div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1rem">
        <div>
            <h3>דומיינים, אחסון ו-SSL שפג תוקפם או מתחדשים בקרוב</h3>
            <?php if ($breakingAssets === []): ?>
                <p class="empty">אין נכסים או תעודות SSL שפג תוקפם ב-60 הימים הקרובים.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table" id="breaking-assets-table">
                        <thead>
                            <tr>
                                <th scope="col">נכס / דומיין</th>
                                <th scope="col">לקוח</th>
                                <th scope="col">תוקף</th>
                                <th scope="col">סטטוס</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($breakingAssets as $a): ?>
                            <tr>
                                <td dir="ltr"><a href="/assets/<?= e((int) $a['id']) ?>"><strong><?= e($a['domain'] ?? ('#' . (int) $a['id'])) ?></strong></a></td>
                                <td>
                                    <?php if (!empty($a['client_id'])): ?>
                                        <a href="/clients/<?= e((int) $a['client_id']) ?>?tab=assets"><?= e($a['client_name'] ?? ('#' . (int) $a['client_id'])) ?></a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td dir="ltr"><?= e($a['expiry_date'] ?? '—') ?></td>
                                <td>
                                    <span class="pill <?= $a['expiry_state'] === 'expired' ? 'pill-danger' : 'pill-warn' ?>">
                                        <?= e($a['expiry_label']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <h3>בנקי שעות בחריגה שלילית או מעל 80% ניצול</h3>
            <?php if ($alertBanks === []): ?>
                <p class="empty">כל בנקי השעות הפעילים בטווח תקין.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table" id="breaking-banks-table">
                        <thead>
                            <tr>
                                <th scope="col">לקוח</th>
                                <th scope="col">בנק שעות</th>
                                <th scope="col">נוצל / הקצאה</th>
                                <th scope="col">יתרה</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($alertBanks as $b): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($b['client_id'])): ?>
                                        <a href="/clients/<?= e((int) $b['client_id']) ?>?tab=time"><strong><?= e($b['client_name'] ?? ('#' . (int) $b['client_id'])) ?></strong></a>
                                    <?php else: ?>
                                        <strong><?= e($b['client_name'] ?? '—') ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($b['name']) ?></td>
                                <td dir="ltr"><?= e(number_format((float) $b['used_hours'], 1)) ?>h / <?= e(number_format((float) $b['allowance_hours'], 1)) ?>h (<?= e((int) $b['pct']) ?>%)</td>
                                <td dir="ltr">
                                    <span class="pill <?= (float) $b['remaining_hours'] < 0 ? 'pill-danger' : 'pill-warn' ?>">
                                        <?= e(number_format((float) $b['remaining_hours'], 1)) ?>h
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===================================================================== -->
<!-- 3. כמה כסף (Money — Realized Income vs Open Demands)                  -->
<!-- ===================================================================== -->
<?php if (!empty($canFinance)): ?>
<section class="card" id="sec-money" aria-labelledby="money-title">
    <div class="card-head">
        <div>
            <p class="eyebrow">3 · חוק המסמך החשבונאי (אפס ספירה כפולה)</p>
            <h2 id="money-title">כמה כסף — הכנסה ממומשת מול דרישות תשלום</h2>
        </div>
        <span class="pill pill-ok">305/320/330 = הכנסה · 400 = תשלום · 300 = דרישת תשלום</span>
    </div>

    <div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1rem">
        <div>
            <h3>הפרדה חשבונאית מאומתת (כלל השנים)</h3>
            <div class="table-wrap">
                <table class="table" id="money-separation-table">
                    <thead>
                        <tr>
                            <th scope="col">סיווג מסמך</th>
                            <th scope="col">נספר כהכנסה?</th>
                            <th scope="col">כמות</th>
                            <th scope="col">סכום (₪)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>חשבונית מס / מס-קבלה (305/320/330)</strong></td>
                            <td><span class="pill pill-ok">כן (הכנסה ממומשת)</span></td>
                            <td><?= e((int) ($sep['invoice']['count'] ?? 0)) ?></td>
                            <td dir="ltr"><strong>₪<?= e(number_format((float) ($sep['invoice']['amount'] ?? 0), 0)) ?></strong></td>
                        </tr>
                        <tr>
                            <td><strong>קבלה עצמאית ללא חשבונית תואמת (400)</strong></td>
                            <td><span class="pill pill-ok">כן (הכנסה ממומשת)</span></td>
                            <td><?= e((int) ($sep['receipt_unmatched']['count'] ?? 0)) ?></td>
                            <td dir="ltr"><strong>₪<?= e(number_format((float) ($sep['receipt_unmatched']['amount'] ?? 0), 0)) ?></strong></td>
                        </tr>
                        <tr>
                            <td>קבלה מנוטרלת מול חשבונית מס (400)</td>
                            <td><span class="pill">לא (אישור תשלום בלבד)</span></td>
                            <td><?= e((int) ($sep['receipt_matched_to_invoice']['count'] ?? 0)) ?></td>
                            <td dir="ltr" class="muted">₪<?= e(number_format((float) ($sep['receipt_matched_to_invoice']['amount'] ?? 0), 0)) ?></td>
                        </tr>
                        <tr>
                            <td><strong>חשבון עסקה פתוח לגבייה (300 · proforma_open)</strong></td>
                            <td><span class="pill pill-warn">לא (דרישת תשלום פתוחה)</span></td>
                            <td><?= e((int) ($sep['proforma_open']['count'] ?? 0)) ?></td>
                            <td dir="ltr"><strong>₪<?= e(number_format((float) ($sep['proforma_open']['amount'] ?? 0), 0)) ?></strong></td>
                        </tr>
                        <tr>
                            <td>חשבון עסקה שנסגר מול חשבונית/קבלה (300 · proforma_matched)</td>
                            <td><span class="pill">לא (נסגר)</span></td>
                            <td><?= e((int) ($sep['proforma_matched']['count'] ?? 0)) ?></td>
                            <td dir="ltr" class="muted">₪<?= e(number_format((float) ($sep['proforma_matched']['amount'] ?? 0), 0)) ?></td>
                        </tr>
                        <tr>
                            <td>חשבון עסקה שסומן "שולם" ב-Morning (300 · proforma_paid_in_morning)</td>
                            <td><span class="pill">לא (שולם ב-Morning)</span></td>
                            <td><?= e((int) ($sep['proforma_paid_in_morning']['count'] ?? 0)) ?></td>
                            <td dir="ltr" class="muted">₪<?= e(number_format((float) ($sep['proforma_paid_in_morning']['amount'] ?? 0), 0)) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <h3>הכנסה ממומשת לפי שנים</h3>
            <?php if (($money['by_year'] ?? []) === []): ?>
                <p class="empty">אין מסמכי הכנסה במערכת.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table" id="money-years-table">
                        <thead>
                            <tr>
                                <th scope="col">שנה</th>
                                <th scope="col">מסמכי הכנסה</th>
                                <th scope="col">הכנסה ממומשת (₪)</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($money['by_year'] as $yr): ?>
                            <tr>
                                <td dir="ltr"><strong><?= e($yr['yr']) ?></strong></td>
                                <td><?= e((int) $yr['docs_count']) ?></td>
                                <td dir="ltr"><strong>₪<?= e(number_format((float) $yr['income'], 0)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ===================================================================== -->
<!-- 4. מה הצוות עשה (What the Team Did)                                   -->
<!-- ===================================================================== -->
<section class="card" id="sec-team" aria-labelledby="team-title">
    <div class="card-head">
        <div>
            <p class="eyebrow">4 · ביצועי צוות ושעות</p>
            <h2 id="team-title">מה הצוות עשה</h2>
        </div>
        <div class="cluster">
            <span class="pill">היום: <b dir="ltr"><?= e(number_format((float) ($team['hours_today'] ?? 0), 1)) ?>h</b></span>
            <span class="pill">7 ימים: <b dir="ltr"><?= e(number_format((float) ($team['hours_7d'] ?? 0), 1)) ?>h</b></span>
            <span class="pill">30 ימים: <b dir="ltr"><?= e(number_format((float) ($team['hours_30d'] ?? 0), 1)) ?>h</b></span>
            <span class="pill">סה״כ מצטבר: <b dir="ltr"><?= e(number_format((float) ($team['hours_total'] ?? 0), 1)) ?>h</b></span>
        </div>
    </div>

    <div class="grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:1rem">
        <div>
            <h3>דיווח שעות לפי איש צוות</h3>
            <?php if (($team['by_user'] ?? []) === []): ?>
                <p class="empty">אין דיווחי שעות להצגה.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table" id="team-users-table">
                        <thead>
                            <tr>
                                <th scope="col">איש צוות</th>
                                <th scope="col">רשומות</th>
                                <th scope="col">30 ימים</th>
                                <th scope="col">סה״כ שעות</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($team['by_user'] as $tu): ?>
                            <tr>
                                <td><strong><?= e($tu['user_name']) ?></strong></td>
                                <td><?= e((int) $tu['entries_count']) ?></td>
                                <td dir="ltr"><?= e(number_format((float) $tu['hours_30d'], 1)) ?>h</td>
                                <td dir="ltr"><strong><?= e(number_format((float) $tu['hours_total'], 1)) ?>h</strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <h3>לקוחות מובילים בהשקעת שעות</h3>
            <?php if (($team['by_client'] ?? []) === []): ?>
                <p class="empty">אין דיווחי שעות לפי לקוח.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table" id="team-clients-table">
                        <thead>
                            <tr>
                                <th scope="col">לקוח</th>
                                <th scope="col">רשומות</th>
                                <th scope="col">30 ימים</th>
                                <th scope="col">סה״כ שעות</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($team['by_client'] as $tc): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($tc['client_id'])): ?>
                                        <a href="/clients/<?= e((int) $tc['client_id']) ?>?tab=time"><strong><?= e($tc['client_name']) ?></strong></a>
                                    <?php else: ?>
                                        <strong><?= e($tc['client_name']) ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((int) $tc['entries_count']) ?></td>
                                <td dir="ltr"><?= e(number_format((float) $tc['hours_30d'], 1)) ?>h</td>
                                <td dir="ltr"><strong><?= e(number_format((float) $tc['hours_total'], 1)) ?>h</strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ===================================================================== -->
<!-- 5. פעילות אחרונה ביומן האירועים (Audit Trail)                         -->
<!-- ===================================================================== -->
<section class="card" id="sec-events" aria-labelledby="events-title">
    <div class="card-head">
        <h2 id="events-title">יומן פעולות אחרונות (Append-Only Audit Log)</h2>
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
                        <code class="muted" dir="ltr" style="font-size:.75rem">(<?= e($ev['action']) ?>)</code>
                    </div>
                    <time class="muted" datetime="<?= e($ev['created_at']) ?>" dir="ltr"><?= e(fmt_dt($ev['created_at'])) ?></time>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
