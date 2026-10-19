<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Assets\AssetService;

/**
 * @var list<array<string, mixed>> $assets
 * @var array<string, int> $kpis
 * @var array<string, string|null> $filters
 * @var bool $internal
 * @var bool $canCreate
 * @var bool $canInspect
 * @var list<array<string, mixed>> $clients
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$kinds = AssetService::KINDS;
$windows = AssetService::WINDOWS;
$sslLabels = AssetService::SSL_STATUSES;
?>
<section class="stats" aria-label="נתוני נכסים">
    <article class="stat card">
        <span class="stat-label">סה"כ נכסים</span>
        <strong class="stat-value" id="assets-kpi-total"><?= e($kpis['total']) ?></strong>
        <span class="stat-hint">דומיינים, אחסון ושרתים</span>
    </article>
    <article class="stat card">
        <span class="stat-label">חידוש תוך 30 יום</span>
        <strong class="stat-value" id="assets-kpi-exp30"><?= e($kpis['expiring_30']) ?></strong>
        <a class="stat-link" href="/assets?window=30">לסינון ←</a>
    </article>
    <article class="stat card">
        <span class="stat-label">פג תוקף</span>
        <strong class="stat-value" id="assets-kpi-expired"><?= e($kpis['expired']) ?></strong>
        <a class="stat-link" href="/assets?window=expired">לסינון ←</a>
    </article>
    <article class="stat card">
        <span class="stat-label">ללא תאריך חידוש</span>
        <strong class="stat-value" id="assets-kpi-missing"><?= e($kpis['missing_expiry']) ?></strong>
        <a class="stat-link" href="/assets?window=missing">לסינון ←</a>
    </article>
</section>

<section class="card" aria-labelledby="assets-filter-title">
    <div class="card-head">
        <h2 id="assets-filter-title">סינון נכסים (<?= e(count($assets)) ?>)</h2>
        <?php if ($filters['client_id'] || $filters['kind'] || $filters['window'] || $filters['q']): ?>
            <a class="btn btn-ghost btn-sm" href="/assets">איפוס סינון</a>
        <?php endif; ?>
    </div>
    <form method="get" action="/assets" class="form form-inline" id="assets-filter-form">
        <?php if ($internal): ?>
        <label class="field">
            <span>לקוח</span>
            <select name="client_id" id="filter-client">
                <option value="">כל הלקוחות</option>
                <?php foreach ($clients as $cl): ?>
                    <option value="<?= e((int) $cl['id']) ?>" <?= (string) ($filters['client_id'] ?? '') === (string) $cl['id'] ? 'selected' : '' ?>><?= e($cl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label class="field">
            <span>סוג</span>
            <select name="kind" id="filter-kind">
                <option value="">הכל</option>
                <?php foreach ($kinds as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= ($filters['kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>חלון חידוש</span>
            <select name="window" id="filter-window">
                <option value="">הכל</option>
                <?php foreach ($windows as $w => $lbl): ?>
                    <option value="<?= e($w) ?>" <?= ($filters['window'] ?? '') === $w ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field field-wide">
            <span>חיפוש (דומיין / ספק / IP / לקוח)</span>
            <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="למשל: co.il, cloudflare...">
        </label>
        <button type="submit" class="btn btn-primary">סינון</button>
    </form>
</section>

<?php if ($canCreate): ?>
<section class="card" id="new" aria-labelledby="new-asset-title">
    <h2 id="new-asset-title">הוספת נכס חדש (שיוך ללקוח חובה)</h2>
    <form method="post" action="/assets" class="form form-grid" id="asset-create-form" novalidate>
        <?= Csrf::field() ?>
        <label class="field">
            <span>לקוח *</span>
            <select name="client_id" required>
                <option value="">בחרו לקוח…</option>
                <?php foreach ($clients as $cl): ?>
                    <option value="<?= e((int) $cl['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $cl['id'] ? 'selected' : '' ?>><?= e($cl['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['client_id'])): ?><small class="field-error" id="err-client_id"><?= e($errors['client_id']) ?></small><?php endif; ?>
        </label>
        <label class="field">
            <span>סוג *</span>
            <select name="kind">
                <?php foreach ($kinds as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= ($old['kind'] ?? 'domain') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>דומיין / מזהה נכס *</span>
            <input type="text" name="domain" dir="ltr" value="<?= e($old['domain'] ?? '') ?>" required maxlength="200" placeholder="example.co.il">
            <?php if (isset($errors['domain'])): ?><small class="field-error" id="err-domain"><?= e($errors['domain']) ?></small><?php endif; ?>
        </label>
        <label class="field">
            <span>תאריך חידוש</span>
            <input type="date" name="expiry_date" dir="ltr" value="<?= e($old['expiry_date'] ?? '') ?>">
            <?php if (isset($errors['expiry_date'])): ?><small class="field-error" id="err-expiry_date"><?= e($errors['expiry_date']) ?></small><?php endif; ?>
        </label>
        <label class="field">
            <span>רשם דומיינים (Registrar)</span>
            <input type="text" name="registrar" value="<?= e($old['registrar'] ?? '') ?>" maxlength="120" placeholder="Box / LiveDNS / Cloudflare">
        </label>
        <label class="field">
            <span>ספק אחסון / שרת</span>
            <input type="text" name="provider" value="<?= e($old['provider'] ?? '') ?>" maxlength="120" placeholder="Cloudways / uPress / AWS">
        </label>
        <label class="field">
            <span>כתובת IP</span>
            <input type="text" name="ip_address" dir="ltr" value="<?= e($old['ip_address'] ?? '') ?>" maxlength="80" placeholder="185.199.108.153">
        </label>
        <label class="field">
            <span>סטטוס SSL</span>
            <select name="ssl_status">
                <option value="">לא נבדק</option>
                <?php foreach ($sslLabels as $sk => $sl): ?>
                    <option value="<?= e($sk) ?>" <?= ($old['ssl_status'] ?? '') === $sk ? 'selected' : '' ?>><?= e($sl) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field field-wide">
            <span>הערות</span>
            <input type="text" name="notes" value="<?= e($old['notes'] ?? '') ?>" maxlength="2000">
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="asset-create-submit">שמירת נכס</button>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="assets-list-title">
    <h2 id="assets-list-title">רשימת נכסים ודומיינים (<?= e(count($assets)) ?>)</h2>
    <?php if ($assets === []): ?>
        <p class="empty">לא נמצאו נכסים התואמים לסינון.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" id="assets-list-table">
                <thead>
                    <tr>
                        <th scope="col">דומיין / נכס</th>
                        <th scope="col">סוג</th>
                        <th scope="col">לקוח</th>
                        <th scope="col">ספק / רשם</th>
                        <th scope="col">IP</th>
                        <th scope="col">SSL</th>
                        <th scope="col">תאריך חידוש</th>
                        <?php if ($internal): ?><th scope="col">גישות</th><th scope="col">פעולות</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $a): ?>
                        <?php $aid = (int) $a['id']; ?>
                        <tr id="asset-row-<?= e($aid) ?>">
                            <td class="strong" dir="ltr">
                                <?php if ($internal): ?>
                                    <a href="/assets/<?= e($aid) ?>"><?= e($a['domain'] ?? ('#' . $aid)) ?></a>
                                <?php else: ?>
                                    <?= e($a['domain'] ?? ('#' . $aid)) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($kinds[$a['kind']] ?? $a['kind']) ?></td>
                            <td>
                                <?php if ($a['client_id'] !== null): ?>
                                    <a href="/clients/<?= e((int) $a['client_id']) ?>?tab=assets"><?= e($a['client_name'] ?? ('#' . (int) $a['client_id'])) ?></a>
                                <?php else: ?>
                                    <span class="muted">לא משויך</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e(implode(' · ', array_filter([$a['provider'] ?? null, $a['registrar'] ?? null]))) ?></td>
                            <td dir="ltr" class="ltr-cell"><?= e($a['ip_address'] ?? '—') ?></td>
                            <td>
                                <?php if (!empty($a['ssl_status'])): ?>
                                    <span class="level level-<?= $a['ssl_status'] === 'valid' ? 'ok' : ($a['ssl_status'] === 'expiring' ? 'warn80' : 'over') ?>">
                                        <?= e($sslLabels[$a['ssl_status']] ?? $a['ssl_status']) ?>
                                    </span>
                                    <?php if (!empty($a['ssl_expiry_date'])): ?><small class="muted"><?= e(fmt_date($a['ssl_expiry_date'])) ?></small><?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($a['expiry_date'])): ?>
                                    <span class="level level-<?= $a['expiry_level'] === 'danger' ? 'over' : ($a['expiry_level'] === 'warn' ? 'warn80' : 'ok') ?>">
                                        <?= e(fmt_date($a['expiry_date'])) ?>
                                    </span>
                                    <?php if ($a['days_left'] !== null): ?>
                                        <small class="muted">(<?= $a['days_left'] < 0 ? 'פג לפני ' . abs($a['days_left']) . ' ימים' : 'בעוד ' . $a['days_left'] . ' ימים' ?>)</small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">חסר תאריך</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($internal): ?>
                            <td>
                                <?php if ((int) ($a['vault_count'] ?? 0) > 0): ?>
                                    <a href="/vault?asset_id=<?= e($aid) ?>" class="badge"><?= e((int) $a['vault_count']) ?> גישות</a>
                                <?php else: ?>
                                    <a href="/vault?client_id=<?= e((int) ($a['client_id'] ?? 0)) ?>&amp;asset_id=<?= e($aid) ?>#new" class="muted small">+ גישה</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-ghost btn-sm" href="/assets/<?= e($aid) ?>">עריכה</a>
                                <?php if ($canInspect && !empty($a['domain'])): ?>
                                    <form method="post" action="/assets/<?= e($aid) ?>/inspect" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="_return" value="/assets<?= !empty($_SERVER['QUERY_STRING']) ? '?' . e($_SERVER['QUERY_STRING']) : '' ?>">
                                        <button type="submit" class="btn btn-ghost btn-sm" id="inspect-asset-<?= e($aid) ?>" title="בדיקת DNS / SSL / WHOIS">🔍 בדיקה</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
