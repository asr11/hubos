<?php
use HubOS\Modules\Assets\AssetService;
use HubOS\Modules\Clients\ClientSeoService;
use HubOS\Modules\Clients\ClientServicesEngine;

/**
 * M11 — Modular Service-Driven Client Portal.
 *
 * Design Invariants:
 *  - ZERO online tasks / internal work boards exposed to clients.
 *  - ZERO "פתיחת תיק לקוח מלא" button.
 *  - ZERO "על מה משלמים" wording -> clean "חבילות שירות פעילות".
 *  - ZERO Hours Bank unless the client actually has the 'hours_bank' service enabled (or an active hours bank when no services configured yet).
 *  - Friendly, human-readable Google Search Console & Google Analytics (GA4) experience.
 *
 * @var array<string, mixed> $user
 * @var array<string, mixed> $portal
 */
$client = $portal['client'] ?? null;
$services = $portal['client_services'] ?? [];
$projects = $portal['projects'] ?? [];
$banks = $portal['hours_banks'] ?? [];
$assets = $portal['assets'] ?? [];
$seoKeywords = $portal['seo_keywords'] ?? [];
$analytics = $portal['analytics_portal'] ?? null;
$metrics = $portal['metrics'] ?? [];

$activeServices = array_values(array_filter($services, static fn (array $s): bool => ($s['status'] ?? 'active') === 'active'));
$completedServices = array_values(array_filter($services, static fn (array $s): bool => ($s['status'] ?? '') === 'completed'));

if ($services !== []) {
    $widgets = $portal['active_widgets'] ?? ClientServicesEngine::activeWidgets($services);
} else {
    // Fallback for legacy clients whose services haven't been explicitly assigned yet
    $widgets = [];
    if ($seoKeywords !== [] || $analytics !== null) {
        $widgets[] = 'gsc_ga4';
        $widgets[] = 'seo_keywords';
    }
    if ($assets !== []) {
        $widgets[] = 'domain_ssl';
    }
    if ($banks !== []) {
        $widgets[] = 'hours_bank';
    }
}
$hasWidget = static fn (string $w): bool => in_array($w, $widgets, true);

$gsc = is_array($analytics['metrics']['gsc'] ?? null) ? $analytics['metrics']['gsc'] : null;
$ga4 = is_array($analytics['metrics']['ga4'] ?? null) ? $analytics['metrics']['ga4'] : null;
$validSslCount = count(array_filter($assets, static fn (array $a): bool => ($a['ssl_status'] ?? '') === 'valid'));

$trendBadge = static function (int $cur, int $prev): string {
    if ($prev <= 0 || $cur === $prev) {
        return '';
    }
    $pct = (int) round((($cur - $prev) / $prev) * 100);
    if ($pct > 0) {
        return '<span class="pill pill-ok seo-stage-pill" dir="ltr">▲ +' . e($pct) . '%</span>';
    }
    return '<span class="pill pill-warn seo-stage-pill" dir="ltr">▼ ' . e($pct) . '%</span>';
};
?>
<section class="hero card" id="client-portal-hero" aria-labelledby="hero-title">
    <div>
        <p class="eyebrow">OS HUB · פורטל לקוח אישי</p>
        <h2 id="hero-title">שלום, <?= e($user['name']) ?></h2>
        <?php if ($client !== null): ?>
            <p class="muted" id="portal-client-name">תיק לקוח: <strong><?= e($client['name']) ?></strong> · תמונת מצב שקופה וידידותית של חבילות השירות הפעילות, ביצועי החיפוש והתנועה לאתר ותקינות התשתית.</p>
            <div class="portal-hero-actions">
                <a class="btn btn-primary btn-sm" id="portal-report-link" href="/clients/<?= e((int) $client['id']) ?>/report">📄 הפקת דוח תקופתי (PDF / הדפסה)</a>
            </div>
        <?php else: ?>
            <p class="muted">המשתמש אינו משויך ללקוח פעיל.</p>
        <?php endif; ?>
    </div>
    <div class="hero-glow" aria-hidden="true"></div>
</section>

<?php if ($client !== null): ?>

<!-- 1. חבילות שירות פעילות -->
<section class="card" id="portal-services-scope" aria-labelledby="portal-scope-title">
    <div class="card-head">
        <h2 id="portal-scope-title">חבילות שירות פעילות (<?= e($services !== [] ? count($activeServices) : count($projects)) ?>)</h2>
        <span class="pill pill-ok">מוגדר לתיק הלקוח</span>
    </div>
    <?php if ($services !== []): ?>
        <div class="service-cards-grid">
            <?php foreach ($services as $svc):
                $isCompleted = ($svc['status'] ?? '') === 'completed';
            ?>
                <article class="service-module-card <?= $isCompleted ? 'is-completed' : 'is-active' ?>">
                    <div class="card-head">
                        <div class="service-module-title">
                            <span aria-hidden="true"><?= e($svc['icon'] ?? '✨') ?></span>
                            <strong><?= fmt_inline_bidi((string) $svc['title']) ?></strong>
                        </div>
                        <div class="cluster">
                            <span class="pill <?= $isCompleted ? '' : 'pill-ok' ?> seo-stage-pill"><?= e($svc['status_label'] ?? 'פעיל') ?></span>
                            <span class="badge"><?= e($svc['cycle_label'] ?? '') ?></span>
                        </div>
                    </div>
                    <?php if (!empty($svc['scope_summary'])): ?>
                        <p class="muted m-0"><?= fmt_inline_bidi((string) $svc['scope_summary']) ?></p>
                    <?php endif; ?>
                    <?php
                    $sm = $svc['metrics'] ?? [];
                    $hasCustomMetrics = !empty($sm['metric_1_label']) || !empty($sm['metric_2_label']) || !empty($sm['metric_3_label']) || !empty($sm['live_url']);
                    if ($hasCustomMetrics):
                    ?>
                        <div class="service-custom-kpis">
                            <?php for ($mi = 1; $mi <= 3; $mi++):
                                if (!empty($sm["metric_{$mi}_label"]) && isset($sm["metric_{$mi}_value"])): ?>
                                <div class="service-mini-kpi">
                                    <span class="muted small"><?= e((string) $sm["metric_{$mi}_label"]) ?></span>
                                    <strong dir="ltr"><?= e((string) $sm["metric_{$mi}_value"]) ?></strong>
                                </div>
                            <?php endif; endfor; ?>
                            <?php if (!empty($sm['live_url'])): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= e((string) $sm['live_url']) ?>" target="_blank" rel="noopener noreferrer">פתיחת קישור חי ↗</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php elseif ($projects !== []): ?>
        <div class="stack">
            <?php foreach ($projects as $p): ?>
                <article class="card">
                    <div class="card-head">
                        <strong><?= fmt_inline_bidi((string) ($p['name'] ?? '')) ?></strong>
                        <span class="pill pill-ok">פעיל</span>
                    </div>
                    <?php if (!empty($p['description'])): ?>
                        <div class="muted mt-sm"><?= fmt_rich_text((string) $p['description']) ?></div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="muted m-0">חבילות השירות של התיק מעודכנות על ידי מנהל התיק.</p>
    <?php endif; ?>
</section>

<!-- 2. מסך קידום אורגני: Google Search Console & Google Analytics (חוויה ידידותית) -->
<?php if ($hasWidget('gsc_ga4')): ?>
<section class="card" id="portal-google-insights" aria-labelledby="portal-google-title">
    <div class="card-head">
        <div>
            <p class="eyebrow">📊 נתוני חיפוש ותנועה בזמן אמת (28 ימים אחרונים)</p>
            <h2 id="portal-google-title">מה באמת קורה באתר שלך בגוגל — Search Console &amp; Analytics</h2>
        </div>
        <?php if (!empty($analytics['last_synced_at'])): ?>
            <span class="pill pill-ok seo-stage-pill">עודכן מגוגל: <bdi dir="ltr"><?= e(fmt_date((string) $analytics['last_synced_at'])) ?></bdi></span>
        <?php else: ?>
            <span class="pill seo-stage-pill">מוכן לסנכרון נתוני גוגל</span>
        <?php endif; ?>
    </div>

    <?php if ($gsc !== null || $ga4 !== null): ?>
        <div class="stats mt-sm" aria-label="מדדי גוגל ואנליטיקס">
            <?php if ($gsc !== null): ?>
                <article class="stat card">
                    <span class="stat-label">חשיפות בחיפוש גוגל</span>
                    <strong class="stat-value"><bdi dir="ltr"><?= e(number_format((int) $gsc['impressions'])) ?></bdi></strong>
                    <span class="stat-hint">כמה פעמים העסק הופיע בגוגל <?= $trendBadge((int) $gsc['impressions'], (int) ($gsc['prev_impressions'] ?? 0)) ?></span>
                </article>
                <article class="stat card">
                    <span class="stat-label">כניסות אורגניות מגוגל</span>
                    <strong class="stat-value"><bdi dir="ltr"><?= e(number_format((int) $gsc['clicks'])) ?></bdi></strong>
                    <span class="stat-hint">גולשים שלחצו ונכנסו לאתר <?= $trendBadge((int) $gsc['clicks'], (int) ($gsc['prev_clicks'] ?? 0)) ?></span>
                </article>
                <article class="stat card">
                    <span class="stat-label">מיקום ממוצע בגוגל</span>
                    <strong class="stat-value"><bdi dir="ltr"><?= (float) $gsc['avg_position'] > 0 ? '#' . e(number_format((float) $gsc['avg_position'], 1)) : '—' ?></bdi></strong>
                    <span class="stat-hint">יחס הקלקה (CTR): <bdi dir="ltr"><?= e(number_format((float) $gsc['ctr_pct'], 1)) ?>%</bdi></span>
                </article>
            <?php endif; ?>
            <?php if ($ga4 !== null): ?>
                <article class="stat card">
                    <span class="stat-label">מבקרים באתר (Analytics)</span>
                    <strong class="stat-value"><bdi dir="ltr"><?= e(number_format((int) $ga4['users'])) ?></bdi></strong>
                    <span class="stat-hint"><bdi dir="ltr"><?= e(number_format((int) $ga4['page_views'])) ?></bdi> צפיות בעמודים <?= $trendBadge((int) $ga4['users'], (int) ($ga4['prev_users'] ?? 0)) ?></span>
                </article>
            <?php endif; ?>
        </div>

        <?php if (!empty($gsc['top_queries']) || !empty($gsc['top_pages']) || !empty($ga4['top_pages'])): ?>
            <div class="grid-2-wide mt-md">
                <?php if (!empty($gsc['top_queries'])): ?>
                <div>
                    <h3>ביטויים שאנשים חיפשו בגוגל ומצאו אותך</h3>
                    <div class="table-wrap">
                        <table class="table table-mobile-cards" id="portal-gsc-queries-table">
                            <thead>
                                <tr>
                                    <th scope="col">ביטוי חיפוש</th>
                                    <th scope="col">קליקים</th>
                                    <th scope="col">חשיפות</th>
                                    <th scope="col">מיקום ממוצע</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($gsc['top_queries'] as $qr): ?>
                                <tr>
                                    <td data-label="ביטוי חיפוש"><strong><?= e((string) $qr['query']) ?></strong></td>
                                    <td data-label="קליקים"><bdi dir="ltr"><?= e((int) $qr['clicks']) ?></bdi></td>
                                    <td data-label="חשיפות"><bdi dir="ltr"><?= e(number_format((int) $qr['impressions'])) ?></bdi></td>
                                    <td data-label="מיקום ממוצע"><bdi dir="ltr">#<?= e(number_format((float) $qr['position'], 1)) ?></bdi></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($gsc['top_pages'])): ?>
                <div>
                    <h3>העמודים המובילים באתר בכניסות מגוגל</h3>
                    <div class="table-wrap">
                        <table class="table table-mobile-cards" id="portal-gsc-pages-table">
                            <thead>
                                <tr>
                                    <th scope="col">עמוד באתר</th>
                                    <th scope="col">קליקים</th>
                                    <th scope="col">חשיפות</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($gsc['top_pages'] as $pg):
                                $pUrl = (string) ($pg['url'] ?? '');
                                $pLabel = urldecode((string) preg_replace('#^https?://#i', '', rtrim($pUrl, '/')));
                            ?>
                                <tr>
                                    <td data-label="עמוד באתר"><a class="seo-url-link" dir="ltr" href="<?= e($pUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($pLabel) ?> ↗</a></td>
                                    <td data-label="קליקים"><bdi dir="ltr"><?= e((int) $pg['clicks']) ?></bdi></td>
                                    <td data-label="חשיפות"><bdi dir="ltr"><?= e(number_format((int) $pg['impressions'])) ?></bdi></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="google-friendly-ready">
            <p class="m-0">
                <strong>חיבור ישיר ל-Google Search Console ול-Google Analytics מוכן להפעלה בתיק שלך</strong>
                <?php if (!empty($analytics['ga4_measurement_id'])): ?>
                    (מזהה מדידה פעיל באתר: <code dir="ltr"><?= e((string) $analytics['ga4_measurement_id']) ?></code>).
                <?php endif; ?>
                ברגע שהרשאת הצפייה מאושרת מול גוגל, יוצגו כאן אוטומטית החשיפות, הכניסות מהחיפוש, המבקרים באתר והביטויים המובילים בתצוגה פשוטה וברורה.
            </p>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- 3. טבלת מילות מפתח ממוקדות בקידום אורגני (SEO & GEO) -->
<?php if ($hasWidget('seo_keywords') && $seoKeywords !== []): ?>
<section class="card" id="portal-seo-keywords" aria-labelledby="portal-seo-title">
    <div class="card-head">
        <h2 id="portal-seo-title">🔍 ביטויי מפתח ממוקדים בקידום אורגני (<?= e(count($seoKeywords)) ?>)</h2>
        <span class="muted">עמודי יעד מרכזיים המקודמים באתר</span>
    </div>
    <div class="table-wrap">
        <table class="table table-mobile-cards" id="portal-seo-table">
            <thead>
                <tr>
                    <th scope="col">מילת מפתח / ביטוי יעד</th>
                    <th scope="col">עמוד יעד באתר</th>
                    <th scope="col">סטטוס קידום</th>
                    <th scope="col">מיקום בגוגל</th>
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
                    <td data-label="מילת מפתח"><strong><?= e($kw['keyword']) ?></strong></td>
                    <td data-label="עמוד יעד">
                        <?php if ($url !== ''): ?>
                            <a class="seo-url-link" dir="ltr" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($urlLabel) ?> ↗</a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="סטטוס קידום"><span class="pill seo-stage-pill kw-stage-<?= e($stage) ?>"><?= e(ClientSeoService::STAGES[$stage] ?? $stage) ?></span></td>
                    <td data-label="מיקום בגוגל">
                        <?php if ($rank !== null): ?>
                            <strong dir="ltr">#<?= e($rank) ?></strong>
                            <?php if ($prev !== null && $prev > $rank): ?>
                                <span class="pill pill-ok seo-stage-pill" dir="ltr">▲ +<?= e($prev - $rank) ?></span>
                            <?php elseif ($prev !== null && $prev < $rank): ?>
                                <span class="pill pill-warn seo-stage-pill" dir="ltr">▼ -<?= e($rank - $prev) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">מתעדכן מ-Search Console</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- 4. תקינות אתר, דומיינים ואבטחת SSL (מוצג רק לבעלי שירות אחסון/תשתית/נכס) -->
<?php if ($hasWidget('domain_ssl') && $assets !== []): ?>
<section class="card" id="portal-assets-section" aria-labelledby="portal-assets-title">
    <div class="card-head">
        <h2 id="portal-assets-title">🛡️ תקינות אתר, דומיינים ואבטחת SSL (<?= e(count($assets)) ?>)</h2>
        <?php if ($validSslCount > 0): ?>
            <span class="pill pill-ok seo-stage-pill">🔒 תעודת SSL תקינה ומאומתת</span>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="table table-mobile-cards" id="portal-assets-table">
            <thead>
                <tr>
                    <th scope="col">דומיין / אתר</th>
                    <th scope="col">תשתית הגשה ואבטחה</th>
                    <th scope="col">אבטחת SSL</th>
                    <th scope="col">תוקף רישום דומיין</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assets as $a):
                $sslOk = ($a['ssl_status'] ?? '') === 'valid';
            ?>
                <tr>
                    <td data-label="דומיין / אתר">
                        <?php if (!empty($a['domain'])): ?>
                            <a class="seo-url-link" dir="ltr" href="https://<?= e((string) $a['domain']) ?>" target="_blank" rel="noopener noreferrer"><strong><?= e((string) $a['domain']) ?></strong> ↗</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td data-label="תשתית הגשה ואבטחה"><bdi><?= e($a['provider'] ?? '—') ?></bdi></td>
                    <td data-label="אבטחת SSL">
                        <?php if ($sslOk): ?>
                            <span class="pill pill-ok seo-stage-pill">🔒 תקין<?= !empty($a['ssl_expiry_date']) ? ' עד ' . e(fmt_date((string) $a['ssl_expiry_date'])) : '' ?></span>
                        <?php else: ?>
                            <span class="pill pill-warn seo-stage-pill"><?= e(AssetService::SSL_STATUSES[$a['ssl_status'] ?? ''] ?? 'בבדיקה') ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="תוקף רישום דומיין"><bdi dir="ltr"><?= e(fmt_date($a['expiry_date'] ?? null) ?: '—') ?></bdi></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- 5. בנק שעות (מוצג אך ורק ללקוח שהוגדר לו שירות בנק שעות!) -->
<?php if ($hasWidget('hours_bank') && $banks !== []): ?>
<section class="card" id="portal-banks-section" aria-labelledby="portal-banks-title">
    <div class="card-head">
        <h2 id="portal-banks-title">⏱️ מאזן בנק שעות (<?= e(count($banks)) ?>)</h2>
    </div>
    <div class="table-wrap">
        <table class="table table-mobile-cards" id="portal-banks-table">
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
                    <td data-label="שם בנק"><strong><?= fmt_inline_bidi((string) $b['name']) ?></strong></td>
                    <td data-label="מכסה"><bdi dir="ltr"><?= e(number_format((float) $b['allowance_hours'], 1)) ?>h</bdi></td>
                    <td data-label="נוצל"><bdi dir="ltr"><?= e(number_format((float) $b['used_hours'], 1)) ?>h (<?= e((int) $b['pct']) ?>%)</bdi></td>
                    <td data-label="יתרה זמינה"><strong dir="ltr"><?= e(number_format((float) $b['remaining_hours'], 1)) ?>h</strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>
