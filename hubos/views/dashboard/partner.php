<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Partners\PartnerService;

/**
 * @var array<string, mixed> $user
 * @var array<string, mixed> $partner
 */
$pct = (float) ($partner['commission_pct'] ?? 10.0);
$clients = $partner['clients'] ?? [];
$leads = $partner['leads'] ?? [];
$metrics = $partner['metrics'] ?? [];
?>
<section class="hero card" id="partner-portal-hero" aria-labelledby="partner-hero-title">
    <div>
        <p class="eyebrow">OS HUB · פורטל שותפים וסוכני מכירות (מבודד)</p>
        <h2 id="partner-hero-title">שלום, <?= e($user['name']) ?></h2>
        <p class="muted">משולש הערך הנצחי · שקיפות מלאה על הלידים שהגשת, הלקוחות שנסגרו והתמלוגים שלך (<b dir="ltr"><?= e(number_format($pct, 0)) ?>%</b>).</p>
    </div>
    <div class="hero-glow" aria-hidden="true"></div>
</section>

<section class="stats" aria-label="מדדי שותף מכירות">
    <article class="stat card">
        <span class="stat-label">לידים שהוגשו</span>
        <strong class="stat-value" id="partner-stat-leads"><?= e((int) ($metrics['leads_count'] ?? 0)) ?></strong>
        <span class="stat-hint">מעקב סטטוס שקוף</span>
    </article>
    <article class="stat card">
        <span class="stat-label">לקוחות פעילים שלי</span>
        <strong class="stat-value" id="partner-stat-clients"><?= e((int) ($metrics['clients_count'] ?? 0)) ?></strong>
        <span class="stat-hint">משויכים לחשבון השותף</span>
    </article>
    <article class="stat card">
        <span class="stat-label">מחזור עסקאות ששולם (לפני מע״מ)</span>
        <strong class="stat-value" id="partner-stat-net"><bdi dir="ltr">₪<?= e(number_format((float) ($metrics['realized_net'] ?? 0), 0)) ?></bdi></strong>
        <span class="stat-hint">מאומת מול מסמכי אמת</span>
    </article>
    <article class="stat card">
        <span class="stat-label">סה״כ תמלוגים שנצברו (<bdi dir="ltr"><?= e(number_format($pct, 0)) ?>%</bdi>)</span>
        <strong class="stat-value" id="partner-stat-royalties"><bdi dir="ltr">₪<?= e(number_format((float) ($metrics['royalties_total'] ?? 0), 0)) ?></bdi></strong>
        <span class="stat-hint">מלקוחות משויכים ששילמו</span>
    </article>
</section>

<section class="card" aria-labelledby="new-lead-title">
    <div class="card-head">
        <h2 id="new-lead-title">+ הגשת ליד חדש למערכת</h2>
        <span class="muted">הליד משויך אוטומטית לשמך מרגע השליחה</span>
    </div>
    <form method="post" action="/partner/leads" class="form-grid" id="partner-lead-form">
        <?= Csrf::field() ?>
        <label class="field">
            <span>שם העסק / איש קשר <abbr title="שדה חובה">*</abbr></span>
            <input type="text" name="name" id="lead-name" required maxlength="160" placeholder="לדוגמה: כהן הנדסה בע״מ">
        </label>
        <label class="field">
            <span>טלפון נייד <abbr title="חובה טלפון או אימייל">*</abbr></span>
            <input type="tel" name="phone" id="lead-phone" maxlength="40" dir="ltr" autocomplete="tel" placeholder="050-0000000">
        </label>
        <label class="field">
            <span>אימייל</span>
            <input type="email" name="email" id="lead-email" maxlength="254" dir="ltr" autocomplete="email" placeholder="office@example.co.il">
        </label>
        <label class="field">
            <span>הערות / צורך העסק</span>
            <input type="text" name="notes" id="lead-notes" maxlength="1000" placeholder="שדרוג אתר, קטלוג B2B, קידום אורגני...">
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="lead-submit">שליחת ליד ושיוך אוטומטי</button>
        </div>
    </form>
</section>

<section class="grid-2-wide">
    <article class="card" aria-labelledby="partner-clients-title">
        <div class="card-head">
            <h2 id="partner-clients-title">הלקוחות שלי ותמלוגים (<?= e(count($clients)) ?>)</h2>
        </div>
        <?php if ($clients === []): ?>
            <p class="empty">עדיין לא שויכו לקוחות משלמים לחשבון השותף שלך.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-mobile-cards" id="partner-clients-table">
                    <thead>
                        <tr>
                            <th scope="col">לקוח</th>
                            <th scope="col">דומיין</th>
                            <th scope="col">שולם (נטו)</th>
                            <th scope="col">התמלוגים שלי</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clients as $c): ?>
                        <tr>
                            <td data-label="לקוח"><strong><?= e($c['name']) ?></strong></td>
                            <td data-label="דומיין"><bdi dir="ltr"><?= e($c['domain'] ?? '—') ?></bdi></td>
                            <td data-label="שולם (נטו)"><bdi dir="ltr">₪<?= e(number_format((float) $c['realized_net'], 0)) ?></bdi></td>
                            <td data-label="התמלוגים שלי"><strong dir="ltr">₪<?= e(number_format((float) $c['royalty_amount'], 0)) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>

    <article class="card" aria-labelledby="partner-leads-title">
        <div class="card-head">
            <h2 id="partner-leads-title">לידים שהגשתי (<?= e(count($leads)) ?>)</h2>
        </div>
        <?php if ($leads === []): ?>
            <p class="empty">טרם הוגשו לידים. השתמש בטופס למעלה לרישום ליד ראשון.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table table-mobile-cards" id="partner-leads-table">
                    <thead>
                        <tr>
                            <th scope="col">שם ליד</th>
                            <th scope="col">טלפון / מייל</th>
                            <th scope="col">סטטוס</th>
                            <th scope="col">תאריך</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($leads as $l): ?>
                        <tr>
                            <td data-label="שם ליד">
                                <strong><?= e($l['name']) ?></strong>
                                <?php if (!empty($l['notes'])): ?>
                                    <div class="muted"><?= e($l['notes']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="טלפון / מייל"><bdi dir="ltr"><?= e($l['phone'] ?: ($l['email'] ?? '—')) ?></bdi></td>
                            <td data-label="סטטוס"><span class="pill seo-stage-pill"><?= e(PartnerService::LEAD_STATUSES[$l['status']] ?? $l['status']) ?></span></td>
                            <td data-label="תאריך"><bdi dir="ltr"><?= e(fmt_date($l['created_at'] ?? null)) ?></bdi></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </article>
</section>
