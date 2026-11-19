<?php
use HubOS\Modules\Assets\AssetService;
use HubOS\Modules\Work\TaskService;

/**
 * Print & PDF-ready RTL Hebrew Client Status & Hours Report.
 *
 * @var array<string, mixed> $report
 */
$c = $report['client'];
$ts = $report['time_summary'];
$ks = $report['task_summary'];
$banks = $report['hours_banks'];
$entries = $report['time_entries'];
$tasks = $report['tasks'];
$projects = $report['projects'];
$assets = $report['assets'];
$isInternalScope = ($report['scope'] ?? 'client') === 'internal';
$familyRolled = !empty($report['family_rolled']);

$hm = static function (int $sec): string {
    $sec = max(0, $sec);
    return sprintf('%d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
};

$taskStatusLabels = [
    'todo'  => 'לביצוע',
    'doing' => 'בתהליך',
    'done'  => 'בוצע',
];
$projStatusLabels = [
    'active' => 'פעיל',
    'done'   => 'הסתיים',
    'paused' => 'מושהה',
];
$sslLabels = AssetService::SSL_STATUSES;
$assetKinds = AssetService::KINDS;
?>
<article class="report-sheet" id="report-sheet">
    <div class="report-letterhead">
        <div class="report-brand-row">
            <div class="report-brand-id">
                <span class="brand-mark report-brand-mark" aria-hidden="true">OS</span>
                <div>
                    <p class="report-issuer">האב מערכות מתקדמות בע״מ · OS HUB</p>
                    <h1 id="report-title">דוח סטטוס עבודה, שעות ונכסים דיגיטליים</h1>
                </div>
            </div>
            <div class="report-doc-meta">
                <div><span class="report-meta-k">תאריך הפקה:</span> <bdi dir="ltr"><?= e($report['generated_at_il']) ?></bdi></div>
                <div><span class="report-meta-k">תקופת שעות:</span> <strong><?= e($report['period_label']) ?></strong></div>
                <?php if ($isInternalScope): ?>
                    <div><span class="report-meta-k">סיווג מסמך:</span> <strong>תצוגת עבודה פנימית</strong></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="report-client-banner">
            <div>
                <span class="report-meta-k">לקוח:</span>
                <strong class="report-client-name" id="report-client-name"><?= e($c['name']) ?></strong>
            </div>
            <?php if (!empty($c['tax_id'])): ?>
                <div><span class="report-meta-k">ח.פ / ע.מ:</span> <bdi dir="ltr"><?= e($c['tax_id']) ?></bdi></div>
            <?php endif; ?>
            <?php if (!empty($c['domain'])): ?>
                <div><span class="report-meta-k">דומיין ראשי:</span> <bdi dir="ltr"><?= e($c['domain']) ?></bdi></div>
            <?php endif; ?>
            <?php if (!empty($c['city'])): ?>
                <div><span class="report-meta-k">יישוב:</span> <?= e($c['city']) ?></div>
            <?php endif; ?>
        </div>

        <p class="report-lead">
            מסמך זה מרכז בשקיפות מלאה את מאזן השעות, בנקי השעות הפעילים, סטטוס המשימות והפרויקטים, ותקינות הנכסים הדיגיטליים המשויכים לתיק הלקוח נכון למועד ההפקה.
        </p>
    </div>

    <section class="report-kpis" aria-label="מדדים מרכזיים">
        <div class="report-kpi">
            <span class="report-kpi-label"><?= $report['selected_period'] === 'all' ? 'סה״כ שעות מדווחות' : 'שעות בתקופה שנבחרה' ?></span>
            <strong class="report-kpi-val" id="report-kpi-hours" dir="ltr"><?= e(fmt_hours($ts['period_hours'])) ?>h</strong>
            <span class="report-kpi-sub"><?= e($ts['entries_count']) ?> רישומי עבודה · החודש: <bdi dir="ltr"><?= e(fmt_hours($ts['hours_this_month'])) ?>h</bdi></span>
        </div>
        <div class="report-kpi">
            <span class="report-kpi-label">יתרת בנק שעות</span>
            <strong class="report-kpi-val" id="report-kpi-bank" dir="ltr">
                <?= $ts['bank_remaining'] === null ? '—' : e(fmt_hours($ts['bank_remaining'])) . 'h' ?>
            </strong>
            <span class="report-kpi-sub"><?= $ts['bank_remaining'] === null ? 'ללא בנק שעות פעיל' : e(count($banks)) . ' בנקי שעות בתיק' ?></span>
        </div>
        <div class="report-kpi">
            <span class="report-kpi-label">סטטוס משימות</span>
            <strong class="report-kpi-val" id="report-kpi-tasks"><?= e($ks['done']) ?> / <?= e($ks['total']) ?></strong>
            <span class="report-kpi-sub">הושלמו (<?= e($ks['completion_pct']) ?>%) · בטיפול: <?= e($ks['doing'] + $ks['todo']) ?></span>
        </div>
        <div class="report-kpi">
            <span class="report-kpi-label">נכסים ודומיינים</span>
            <strong class="report-kpi-val" id="report-kpi-assets"><?= e(count($assets)) ?></strong>
            <span class="report-kpi-sub"><?= e(count($projects)) ?> פרויקטים פעילים בתיק</span>
        </div>
    </section>

    <?php if ($banks !== []): ?>
    <section class="report-section" aria-labelledby="report-banks-heading">
        <h2 id="report-banks-heading">1. מאזן בנקי שעות (<?= e(count($banks)) ?>)</h2>
        <div tabindex="0" role="region" aria-labelledby="report-banks-caption" class="report-table-wrap">
            <table class="report-table report-table-banks" id="report-banks-table">
                <caption id="report-banks-caption" class="visually-hidden">פירוט בנקי שעות ללקוח <?= e($c['name']) ?></caption>
                <thead>
                    <tr>
                        <th scope="col">שם בנק השעות</th>
                        <th scope="col">סוג ומצב</th>
                        <th scope="col">מכסת שעות</th>
                        <th scope="col">נוצל בפועל</th>
                        <th scope="col">יתרה זמינה</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($banks as $b): $isActive = ($b['status'] ?? '') === 'active'; ?>
                    <tr>
                        <th scope="row"><?= fmt_inline_bidi((string) $b['name']) ?></th>
                        <td>
                            <span class="report-badge <?= $isActive ? 'is-ok' : 'is-muted' ?>"><?= $isActive ? 'פעיל' : 'הסתיים' ?></span>
                            · <?= ($b['period'] ?? 'one_time') === 'monthly' ? 'חודשי' : 'חד-פעמי' ?>
                        </td>
                        <td dir="ltr"><?= e(fmt_hours($b['allowance_hours'])) ?>h</td>
                        <td dir="ltr"><?= e(fmt_hours($b['used_hours'])) ?>h (<?= e((int) $b['pct']) ?>%)</td>
                        <td dir="ltr" class="strong"><?= e(fmt_hours($b['remaining_hours'])) ?>h</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <section class="report-section" aria-labelledby="report-time-heading">
        <h2 id="report-time-heading"><?= $banks !== [] ? '2.' : '1.' ?> פירוט שעות עבודה — <?= e($report['period_label']) ?> (<?= e(count($entries)) ?>)</h2>
        <?php if ($entries === []): ?>
            <p class="report-empty">אין רישומי שעות בתקופה שנבחרה.</p>
        <?php else: ?>
            <div tabindex="0" role="region" aria-labelledby="report-time-caption" class="report-table-wrap">
                <table class="report-table report-table-time" id="report-time-table">
                    <caption id="report-time-caption" class="visually-hidden">פירוט רישומי שעות עבודה בתקופה <?= e($report['period_label']) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">תאריך</th>
                            <th scope="col">פירוט העבודה שבוצעה</th>
                            <?php if ($familyRolled): ?><th scope="col">חברה</th><?php endif; ?>
                            <th scope="col">משך (שע׳:דק׳)</th>
                            <th scope="col">שעות עשרוני</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $te): $sec = (int) ($te['duration_seconds'] ?? 0); ?>
                        <tr>
                            <th scope="row" dir="ltr"><?= e(fmt_date($te['entry_date'] ?? $te['started_at'])) ?></th>
                            <td><?= fmt_inline_bidi((string) ($te['description'] ?? 'עבודה שוטפת')) ?></td>
                            <?php if ($familyRolled): ?><td><?= e($te['origin_name'] ?? '') ?></td><?php endif; ?>
                            <td dir="ltr"><?= e($hm($sec)) ?></td>
                            <td dir="ltr" class="strong"><?= e(fmt_hours($sec / 3600)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                        <tr class="report-total-row">
                            <th scope="row" colspan="<?= $familyRolled ? 3 : 2 ?>">סה״כ שעות בתקופה (<?= e($report['period_label']) ?>)</th>
                            <td dir="ltr" class="strong"><?= e($hm((int) $ts['period_seconds'])) ?></td>
                            <td dir="ltr" class="strong"><?= e(fmt_hours($ts['period_hours'])) ?>h</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="report-section" aria-labelledby="report-tasks-heading">
        <h2 id="report-tasks-heading"><?= $banks !== [] ? '3.' : '2.' ?> סטטוס משימות ופרויקטים (<?= e(count($tasks)) ?>)</h2>
        <?php if ($projects !== []): ?>
            <p class="report-projects-line">
                <strong>פרויקטים בתיק:</strong>
                <?php foreach ($projects as $idx => $p): ?>
                    <?= $idx > 0 ? ' · ' : '' ?><?= fmt_inline_bidi((string) $p['name']) ?>
                    (<?= e($projStatusLabels[$p['status']] ?? $p['status']) ?>)
                <?php endforeach; ?>
            </p>
        <?php endif; ?>

        <?php if ($tasks === []): ?>
            <p class="report-empty">אין משימות להצגה בקטגוריה זו.</p>
        <?php else: ?>
            <div tabindex="0" role="region" aria-labelledby="report-tasks-caption" class="report-table-wrap">
                <table class="report-table report-table-tasks" id="report-tasks-table">
                    <caption id="report-tasks-caption" class="visually-hidden">סטטוס משימות עבור <?= e($c['name']) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">משימה</th>
                            <th scope="col">פרויקט</th>
                            <th scope="col">סטטוס ביצוע</th>
                            <th scope="col">תאריך יעד</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tasks as $t): $sk = (string) ($t['status_key'] ?? 'todo'); ?>
                        <tr>
                            <th scope="row"><?= fmt_inline_bidi((string) $t['title']) ?></th>
                            <td><?= !empty($t['project_name']) ? fmt_inline_bidi((string) $t['project_name']) : '—' ?></td>
                            <td>
                                <span class="report-badge <?= $sk === 'done' ? 'is-ok' : ($sk === 'doing' ? 'is-doing' : 'is-muted') ?>">
                                    <?= $sk === 'done' ? '✓ ' : ($sk === 'doing' ? '◐ ' : '○ ') ?><?= e($taskStatusLabels[$sk] ?? $sk) ?>
                                </span>
                            </td>
                            <td dir="ltr"><?= !empty($t['due_at']) ? e(fmt_date((string) $t['due_at'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="report-section" aria-labelledby="report-assets-heading">
        <h2 id="report-assets-heading"><?= $banks !== [] ? '4.' : '3.' ?> נכסים דיגיטליים, דומיינים ותעודות אבטחה (<?= e(count($assets)) ?>)</h2>
        <?php if ($assets === []): ?>
            <p class="report-empty">אין נכסים דיגיטליים פעילים הרשומים בתיק זה.</p>
        <?php else: ?>
            <div tabindex="0" role="region" aria-labelledby="report-assets-caption" class="report-table-wrap">
                <table class="report-table report-table-assets" id="report-assets-table">
                    <caption id="report-assets-caption" class="visually-hidden">רשימת נכסים ודומיינים מנוטרים</caption>
                    <thead>
                        <tr>
                            <th scope="col">דומיין / מזהה נכס</th>
                            <th scope="col">סוג</th>
                            <th scope="col">ספק / רשם</th>
                            <th scope="col">אבטחת SSL</th>
                            <th scope="col">תוקף חידוש</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($assets as $a): $ssl = (string) ($a['ssl_status'] ?? ''); ?>
                        <tr>
                            <th scope="row" dir="ltr"><?= e($a['domain'] ?? '—') ?></th>
                            <td><?= e($assetKinds[$a['kind'] ?? 'other'] ?? ($a['kind'] ?? '')) ?></td>
                            <td><?= implode(' · ', array_map(static fn (string $s): string => '<bdi>' . e($s) . '</bdi>', array_filter([$a['provider'] ?? null, $a['registrar'] ?? null]))) ?: '—' ?></td>
                            <td>
                                <?php if ($ssl !== ''): ?>
                                    <span class="report-badge <?= $ssl === 'valid' ? 'is-ok' : ($ssl === 'expiring' ? 'is-doing' : 'is-warn') ?>">
                                        <?= $ssl === 'valid' ? '✓ ' : '! ' ?><?= e($sslLabels[$ssl] ?? $ssl) ?>
                                    </span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td dir="ltr"><?= e(fmt_date($a['expiry_date'] ?? null) ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php $seoKeywords = $report['seo_keywords'] ?? []; if ($seoKeywords !== []): ?>
    <section class="report-section" aria-labelledby="report-seo-heading">
        <h2 id="report-seo-heading"><?= $banks !== [] ? '5.' : '4.' ?> מילות מפתח מרכזיות בקידום אורגני (SEO &amp; GEO) (<?= e(count($seoKeywords)) ?>)</h2>
        <div tabindex="0" role="region" aria-labelledby="report-seo-caption" class="report-table-wrap">
            <table class="report-table" id="report-seo-table">
                <caption id="report-seo-caption" class="visually-hidden">מילות מפתח מרכזיות במעקב קידום אורגני</caption>
                <thead>
                    <tr>
                        <th scope="col">ביטוי / מילת מפתח</th>
                        <th scope="col">עמוד יעד</th>
                        <th scope="col">שלב קידום</th>
                        <th scope="col">מיקום מאומת</th>
                        <th scope="col">פעולת קידום</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($seoKeywords as $kw):
                    $st = (string) ($kw['stage'] ?? 'optimizing');
                    $u = (string) ($kw['target_url'] ?? '');
                ?>
                    <tr>
                        <th scope="row"><?= e($kw['keyword']) ?></th>
                        <td dir="ltr"><?= e($u !== '' ? urldecode((string) preg_replace('#^https?://#i', '', rtrim($u, '/'))) : '—') ?></td>
                        <td><?= e(\HubOS\Modules\Clients\ClientSeoService::STAGES[$st] ?? $st) ?></td>
                        <td dir="ltr"><?= $kw['current_rank'] !== null ? '#' . e((int) $kw['current_rank']) : 'במדידה' ?></td>
                        <td><?= e($kw['notes'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</article>
