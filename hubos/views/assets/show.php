<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Assets\AssetService;

/**
 * @var array<string, mixed> $asset
 * @var bool $internal
 * @var bool $canUpdate
 * @var bool $canInspect
 * @var bool $canVault
 * @var list<array<string, mixed>> $clients
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$id = (int) $asset['id'];
$kinds = AssetService::KINDS;
$sslLabels = AssetService::SSL_STATUSES;
$insp = $asset['inspection'] ?? null;
?>
<section class="card" aria-labelledby="asset-title">
    <div class="card-head">
        <div>
            <p class="eyebrow"><a href="/assets">← כל הנכסים</a><?php if ($asset['client_id'] !== null): ?> · <a href="/clients/<?= e((int) $asset['client_id']) ?>?tab=assets"><?= e($asset['client_name'] ?? '') ?></a><?php endif; ?></p>
            <h2 id="asset-title" dir="ltr"><?= e($asset['domain'] ?? ('#' . $id)) ?></h2>
        </div>
        <?php if ($canInspect && !empty($asset['domain'])): ?>
            <form method="post" action="/assets/<?= e($id) ?>/inspect" id="asset-inspect-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="_return" value="/assets/<?= e($id) ?>">
                <button type="submit" class="btn btn-primary" id="asset-inspect-btn">🔍 בדיקת DNS / SSL / WHOIS כעת</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (is_array($insp)): ?>
        <div class="card" id="asset-inspection-results">
            <h3>ממצאי בדיקה אוטומטית אחרונה (<?= e(fmt_dt($insp['inspected_at'] ?? null)) ?>)</h3>
            <div class="grid-2">
                <div>
                    <p><strong>דומיין שנבדק:</strong> <span dir="ltr"><?= e($insp['host'] ?? '') ?></span></p>
                    <p><strong>רשומות DNS A (IP):</strong> <span dir="ltr"><?= e(implode(', ', $insp['dns_a'] ?? []) ?: '—') ?></span></p>
                    <p><strong>שרתי שמות (NS):</strong> <span dir="ltr"><?= e(implode(', ', $insp['dns_ns'] ?? []) ?: '—') ?></span></p>
                    <p><strong>שרתי דואר (MX):</strong> <span dir="ltr"><?= e(implode(', ', $insp['dns_mx'] ?? []) ?: '—') ?></span></p>
                </div>
                <div>
                    <p><strong>תעודת SSL:</strong> <?= e($sslLabels[$insp['ssl_status'] ?? 'unknown'] ?? ($insp['ssl_status'] ?? '')) ?>
                        <?php if (!empty($insp['ssl_expiry_date'])): ?> · תוקף עד <?= e(fmt_date($insp['ssl_expiry_date'])) ?><?php endif; ?>
                        <?php if (!empty($insp['ssl_issuer'])): ?> (מנפיק: <span dir="ltr"><?= e($insp['ssl_issuer']) ?></span>)<?php endif; ?>
                    </p>
                    <p><strong>תפוגת WHOIS:</strong> <?= e(fmt_date($insp['whois_expiry_date'] ?? null) ?: '—') ?></p>
                    <p><strong>רשם (WHOIS):</strong> <?= e($insp['whois_registrar'] ?? '—') ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canUpdate): ?>
        <h3>עריכת פרטי נכס</h3>
        <form method="post" action="/assets/<?= e($id) ?>" class="form form-grid" id="asset-edit-form" novalidate>
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
                <span>דומיין / מזהה *</span>
                <input type="text" name="domain" dir="ltr" value="<?= e($old['domain'] ?? '') ?>" required maxlength="200">
                <?php if (isset($errors['domain'])): ?><small class="field-error" id="err-domain"><?= e($errors['domain']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>תאריך חידוש (YYYY-MM-DD)</span>
                <input type="date" name="expiry_date" dir="ltr" value="<?= e($old['expiry_date'] ?? '') ?>">
                <?php if (isset($errors['expiry_date'])): ?><small class="field-error" id="err-expiry_date"><?= e($errors['expiry_date']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>רשם דומיינים (Registrar)</span>
                <input type="text" name="registrar" value="<?= e($old['registrar'] ?? '') ?>" maxlength="120">
            </label>
            <label class="field">
                <span>ספק אחסון / שרת</span>
                <input type="text" name="provider" value="<?= e($old['provider'] ?? '') ?>" maxlength="120">
            </label>
            <label class="field">
                <span>כתובת IP</span>
                <input type="text" name="ip_address" dir="ltr" value="<?= e($old['ip_address'] ?? '') ?>" maxlength="80">
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
            <label class="field">
                <span>תוקף SSL</span>
                <input type="date" name="ssl_expiry_date" dir="ltr" value="<?= e($old['ssl_expiry_date'] ?? '') ?>">
            </label>
            <label class="field">
                <span>סטטוס נכס</span>
                <input type="text" name="status" value="<?= e($old['status'] ?? 'active') ?>" maxlength="40">
            </label>
            <label class="field field-wide">
                <span>הערות</span>
                <input type="text" name="notes" value="<?= e($old['notes'] ?? '') ?>" maxlength="2000">
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="asset-update-submit">שמירת שינויים</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php if ($canVault): ?>
<section class="card" id="asset-vault" aria-labelledby="asset-vault-title">
    <div class="card-head">
        <h3 id="asset-vault-title">גישות בכספת המקושרות לנכס זה (<?= e(count($asset['vault_items'] ?? [])) ?>)</h3>
        <?php if ($asset['client_id'] !== null): ?>
            <a class="btn btn-ghost btn-sm" href="/vault?client_id=<?= e((int) $asset['client_id']) ?>&amp;asset_id=<?= e($id) ?>#new">+ הוספת גישה לנכס</a>
        <?php endif; ?>
    </div>
    <?php if (empty($asset['vault_items'])): ?>
        <p class="empty">אין רשומות כספת המקושרות ישירות לנכס זה.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" id="asset-vault-table">
                <thead>
                    <tr>
                        <th scope="col">כותרת</th>
                        <th scope="col">שירות</th>
                        <th scope="col">כתובת</th>
                        <th scope="col">משתמש</th>
                        <th scope="col">הצפנה</th>
                        <th scope="col">פעולות</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($asset['vault_items'] as $v): ?>
                        <tr>
                            <td class="strong"><a href="/vault/<?= e((int) $v['id']) ?>"><?= e($v['title'] ?? '') ?></a></td>
                            <td><?= e($v['service'] ?? '') ?></td>
                            <td dir="ltr" class="ltr-cell"><?= e($v['url'] ?? '') ?></td>
                            <td dir="ltr" class="ltr-cell"><?= e($v['username'] ?? '') ?></td>
                            <td><?= e($v['encryption']) ?></td>
                            <td><a class="btn btn-ghost btn-sm" href="/vault/<?= e((int) $v['id']) ?>">פתיחה בכספת</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
