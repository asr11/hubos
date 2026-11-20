<?php
use HubOS\Modules\Assets\AssetService;
use HubOS\Modules\Clients\ClientSeoService;
use HubOS\Modules\Clients\ClientServicesEngine;

/**
 * Print & PDF-ready RTL Hebrew Client Status Report.
 *
 * M11: When modular client_services are configured and scope is 'client',
 * renders strictly the active service packages, Google Search Console & Analytics
 * summary, SEO keywords, Domain/SSL, and ONLY hours bank if enabled — ZERO internal/online tasks!
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
$seoKeywords = $report['seo_keywords'] ?? [];
$clientServices = $report['client_services'] ?? [];
$analytics = $report['analytics_portal'] ?? null;
$isInternalScope = ($report['scope'] ?? 'client') === 'internal';
$familyRolled = !empty($report['family_rolled']);

$isModularClientReport = !$isInternalScope && $clientServices !== [];
$widgets = $isModularClientReport ? ($report['active_widgets'] ?? ClientServicesEngine::activeWidgets($clientServices)) : [];
$hasWidget = static fn (string $w): bool => in_array($w, $widgets, true);

$gsc = is_array($analytics['metrics']['gsc'] ?? null) ? $analytics['metrics']['gsc'] : null;
$ga4 = is_array($analytics['metrics']['ga4'] ?? null) ? $analytics['metrics']['ga4'] : null;

$secNum = 0;
$hm = static function (int $sec): string {
    $sec = max(0, $sec);
    return sprintf('%d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
};

$taskStatusLabels = ['todo' => 'לביצוע', 'doing' => 'בתהליך', 'done' => 'בוצע'];
$projStatusLabels = ['active' => 'פעיל', 'done' => 'הסתיים', 'paused' => 'מושהה'];
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
                    <h1 id="report-title"><?= $isModularClientReport ? 'דוח חבילות שירות, ביצועי אתר ונכסים דיגיטליים' : 'דוח סטטוס עבודה, שעות ונכסים דיגיטליים' ?></h1>
                </div>
            </div>
            <div class="report-doc-meta">
                <div><span class="report-meta-k">תאריך הפקה:</span> <bdi dir="ltr"><?= e($report['generated_at_il']) ?></bdi></div>
                <?php if (!$isModularClientReport): ?>
                    <div><span class="report-meta-k">תקופת שעות:</span> <strong><?= e($report['period_label']) ?></strong></div>
                <?php endif; ?>
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
            <?php if ($isModularClientReport): ?>
                מסמך זה מרכז בשקיפות את חבילות השירות הפעילות, מדדי החשיפה והתנועה של האתר בגוגל ותקינות הדומיין ותעודת ה-SSL נכון למועד ההפקה.
            <?php else: ?>
                מסמך זה מרכז בשקיפות מלאה את מאזן השעות, בנקי השעות הפעילים, סטטוס המשימות והפרויקטים, ותקינות הנכסים הדיגיטליים המשויכים לתיק הלקוח נכון למועד ההפקה.
            <?php endif; ?>
        </p>
    </div>

    <?php if ($isModularClientReport): ?>
        <?php $secNum++; ?>
        <section class="report-section" aria-labelledby="report-services-heading">
            <h2 id="report-services-heading"><?= $secNum ?>. חבילות שירות פעילות (<?= e(count($clientServices)) ?>)</h2>
            <div tabindex="0" role="region" aria-labelledby="report-services-caption" class="report-table-wrap">
                <table class="report-table" id="report-services-table">
                    <caption id="report-services-caption" class="visually-hidden">חבילות שירות בתיק <?= e($c['name']) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">שירות</th>
                            <th scope="col">תכולת השירות</th>
                            <th scope="col">מתכונת</th>
                            <th scope="col">סטטוס</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clientServices as $svc): ?>
                        <tr>
                            <th scope="row"><?= e($svc['title']) ?></th>
                            <td><?= e($svc['scope_summary'] ?? '—') ?></td>
                            <td><?= e($svc['cycle_label'] ?? '') ?></td>
                            <td><span class="report-badge <?= ($svc['status'] ?? '') === 'active' ? 'is-ok' : 'is-muted' ?>"><?= e($svc['status_label'] ?? '') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($hasWidget('gsc_ga4') && ($gsc !== null || $ga4 !== null)): $secNum++; ?>
        <section class="report-section" aria-labelledby="report-google-heading">
            <h2 id="report-google-heading"><?= $secNum ?>. נתוני Google Search Console ו-Google Analytics (28 ימים)</h2>
            <section class="report-kpis" aria-label="מדדי גוגל">
                <?php if ($gsc !== null): ?>
                <div class="report-kpi">
                    <span class="report-kpi-label">חשיפות בחיפוש גוגל</span>
                    <strong class="report-kpi-val"><bdi dir="ltr"><?= e(number_format((int) $gsc['impressions'])) ?></bdi></strong>
                    <span class="report-kpi-sub">הופעות בתוצאות החיפוש</span>
                </div>
                <div class="report-kpi">
                    <span class="report-kpi-label">כניסות אורגניות מגוגל</span>
                    <strong class="report-kpi-val"><bdi dir="ltr"><?= e(number_format((int) $gsc['clicks'])) ?></bdi></strong>
                    <span class="report-kpi-sub">CTR: <bdi dir="ltr"><?= e(number_format((float) $gsc['ctr_pct'], 1)) ?>%</bdi></span>
                </div>
                <div class="report-kpi">
                    <span class="report-kpi-label">מיקום ממוצע בגוגל</span>
                    <strong class="report-kpi-val"><bdi dir="ltr"><?= (float) $gsc['avg_position'] > 0 ? '#' . e(number_format((float) $gsc['avg_position'], 1)) : '—' ?></bdi></strong>
                    <span class="report-kpi-sub">Search Console</span>
                </div>
                <?php endif; ?>
                <?php if ($ga4 !== null): ?>
                <div class="report-kpi">
                    <span class="report-kpi-label">מבקרים באתר (GA4)</span>
                    <strong class="report-kpi-val"><bdi dir="ltr"><?= e(number_format((int) $ga4['users'])) ?></bdi></strong>
                    <span class="report-kpi-sub"><bdi dir="ltr"><?= e(number_format((int) $ga4['page_views'])) ?></bdi> צפיות בעמודים</span>
                </div>
                <?php endif; ?>
            </section>
        </section>
        <?php endif; ?>

        <?php if ($hasWidget('seo_keywords') && $seoKeywords !== []): $secNum++; ?>
        <section class="report-section" aria-labelledby="report-seo-heading">
            <h2 id="report-seo-heading"><?= $secNum ?>. ביטויי מפתח ממוקדים בקידום אורגני (<?= e(count($seoKeywords)) ?>)</h2>
            <div tabindex="0" role="region" aria-labelledby="report-seo-caption" class="report-table-wrap">
                <table class="report-table" id="report-seo-table">
                    <caption id="report-seo-caption" class="visually-hidden">מילות מפתח מרכזיות במעקב קידום אורגני</caption>
                    <thead>
                        <tr>
                            <th scope="col">ביטוי / מילת מפתח</th>
                            <th scope="col">עמוד יעד</th>
                            <th scope="col">סטטוס קידום</th>
                            <th scope="col">מיקום בגוגל</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($seoKeywords as $kw):
                        $st = (string) ($kw['stage'] ?? 'optimizing');
                        $u = (string) ($kw['target_url'] ?? '');
                    ?>
                        <tr>
                            <th scope="row"><?= e($kw['keyword']) ?></th>
                            <td><bdi dir="ltr" class="report-seo-url"><?= e($u !== '' ? urldecode((string) preg_replace('#^https?://#i', '', rtrim($u, '/'))) : '—') ?></bdi></td>
                            <td><span class="report-badge kw-stage-<?= e($st) ?> seo-stage-pill"><?= e(ClientSeoService::STAGES[$st] ?? $st) ?></span></td>
                            <td><?= $kw['current_rank'] !== null ? '<bdi dir="ltr">#' . e((int) $kw['current_rank']) . '</bdi>' : 'מתעדכן מ-Search Console' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($hasWidget('hours_bank') && $banks !== []): $secNum++; ?>
        <section class="report-section" aria-labelledby="report-banks-heading">
            <h2 id="report-banks-heading"><?= $secNum ?>. מאזן בנקי שעות (<?= e(count($banks)) ?>)</h2>
            <div tabindex="0" role="region" aria-labelledby="report-banks-caption" class="report-table-wrap">
                <table class="report-table report-table-banks" id="report-banks-table">
                    <caption id="report-banks-caption" class="visually-hidden">פירוט בנקי שעות ללקוח <?= e($c['name']) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">שם בנק השעות</th>
                            <th scope="col">מכסת שעות</th>
                            <th scope="col">נוצל בפועל</th>
                            <th scope="col">יתרה זמינה</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($banks as $b): ?>
                        <tr>
                            <th scope="row"><?= fmt_inline_bidi((string) $b['name']) ?></th>
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

        <?php if ($hasWidget('domain_ssl') && $assets !== []): $secNum++; ?>
        <section class="report-section" aria-labelledby="report-assets-heading">
            <h2 id="report-assets-heading"><?= $secNum ?>. תקינות אתר, דומיינים ותעודות אבטחה (<?= e(count($assets)) ?>)</h2>
            <div tabindex="0" role="region" aria-labelledby="report-assets-caption" class="report-table-wrap">
                <table class="report-table report-table-assets" id="report-assets-table">
                    <caption id="report-assets-caption" class="visually-hidden">רשימת נכסים ודומיינים מנוטרים</caption>
                    <thead>
                        <tr>
                            <th scope="col">דומיין / אתר</th>
                            <th scope="col">סוג</th>
                            <th scope="col">תשתית ורשם</th>
                            <th scope="col">אבטחת SSL</th>
                            <th scope="col">תוקף חידוש</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($assets as $a): $ssl = (string) ($a['ssl_status'] ?? ''); ?>
                        <tr>
                            <th scope="row"><bdi dir="ltr"><?= e($a['domain'] ?? '—') ?></bdi></th>
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
        </section>
        <?php endif; ?>

    <?php else: ?>
        <!-- Internal scope or legacy hours-based report -->
        <section class="report-kpis" aria-label="מדדים מרכזיים">
            <div class="report-kpi">
                <span class="report-kpi-label"><?= $report['selected_period'] === 'all' ? 'סה״כ שעות מדווחות' : 'שעות בתקופה שנבחרה' ?></span>
                <strong class="report-kpi-val" id="report-kpi-hours"><bdi dir="ltr"><?= e(fmt_hours($ts['period_hours'])) ?>h</bdi></strong>
                <span class="report-kpi-sub"><?= e($ts['entries_count']) ?> רישומי עבודה · החודש: <bdi dir="ltr"><?= e(fmt_hours($ts['hours_this_month'])) ?>h</bdi></span>
            </div>
            <div class="report-kpi">
                <span class="report-kpi-label">יתרת בנק שעות</span>
                <strong class="report-kpi-val" id="report-kpi-bank">
                    <?= $ts['bank_remaining'] === null ? '—' : '<bdi dir="ltr">' . e(fmt_hours($ts['bank_remaining'])) . 'h</bdi>' ?>
                </strong>
                <span class="report-kpi-sub"><?= $ts['bank_remaining'] === null ? 'ללא בנק שעות פעיל' : e(count($banks)) . ' בנקי שעות בתיק' ?></span>
            </div>
            <div class="report-kpi">
                <span class="report-kpi-label">סטטוס משימות</span>
                <strong class="report-kpi-val" id="report-kpi-tasks"><bdi dir="ltr"><?= e($ks['done']) ?> / <?= e($ks['total']) ?></bdi></strong>
                <span class="report-kpi-sub">הושלמו (<bdi dir="ltr"><?= e($ks['completion_pct']) ?>%</bdi>) · בטיפול: <?= e($ks['doing'] + $ks['todo']) ?></span>
            </div>
            <div class="report-kpi">
                <span class="report-kpi-label">נכסים ודומיינים</span>
                <strong class="report-kpi-val" id="report-kpi-assets"><?= e(count($assets)) ?></strong>
                <span class="report-kpi-sub"><?= e(count($projects)) ?> פרויקטים פעילים בתיק</span>
            </div>
        </section>

        <?php if ($banks !== []): $secNum++; ?>
        <section class="report-section" aria-labelledby="report-banks-heading">
            <h2 id="report-banks-heading"><?= $secNum ?>. מאזן בנקי שעות (<?= e(count($banks)) ?>)</h2>
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

        <?php $secNum++; ?>
        <section class="report-section" aria-labelledby="report-time-heading">
            <h2 id="report-time-heading"><?= $secNum ?>. פירוט שעות עבודה — <?= e($report['period_label']) ?> (<?= e(count($entries)) ?>)</h2>
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

        <?php $secNum++; ?>
        <section class="report-section" aria-labelledby="report-tasks-heading">
            <h2 id="report-tasks-heading"><?= $secNum ?>. סטטוס משימות ופרויקטים (<?= e(count($tasks)) ?>)</h2>
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

        <?php $secNum++; ?>
        <section class="report-section" aria-labelledby="report-assets-heading">
            <h2 id="report-assets-heading"><?= $secNum ?>. נכסים דיגיטליים, דומיינים ותעודות אבטחה (<?= e(count($assets)) ?>)</h2>
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
                                <th scope="row"><bdi dir="ltr"><?= e($a['domain'] ?? '—') ?></bdi></th>
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
    <?php endif; ?>
</article>
