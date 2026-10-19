<?php
use HubOS\Core\Csrf;

/**
 * @var list<array<string, mixed>> $items
 * @var array<string, string|null> $filters
 * @var bool $canCreate
 * @var bool $canReveal
 * @var bool $hasVaultKey
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $assetsList
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$encryptedCount = count(array_filter($items, static fn (array $i): bool => ($i['encryption'] ?? '') === 'aes-256-gcm'));
$linkedAssetCount = count(array_filter($items, static fn (array $i): bool => ($i['asset_id'] ?? null) !== null));
?>
<section class="stats" aria-label="נתוני כספת">
    <article class="stat card">
        <span class="stat-label">רשומות בכספת</span>
        <strong class="stat-value" id="vault-kpi-total"><?= e(count($items)) ?></strong>
        <span class="stat-hint">מטא-דאטה בלבד בטבלה</span>
    </article>
    <article class="stat card">
        <span class="stat-label">מוצפנות AES-256-GCM</span>
        <strong class="stat-value" id="vault-kpi-encrypted"><?= e($encryptedCount) ?></strong>
        <span class="stat-hint"><?= $hasVaultKey ? 'מפתח הצפנה פעיל' : 'HUBOS_VAULT_KEY לא הוגדר' ?></span>
    </article>
    <article class="stat card">
        <span class="stat-label">מקושרות לנכס/דומיין</span>
        <strong class="stat-value" id="vault-kpi-linked-assets"><?= e($linkedAssetCount) ?></strong>
        <span class="stat-hint">Asset-to-Credential</span>
    </article>
</section>

<section class="card" aria-labelledby="vault-filter-title">
    <div class="card-head">
        <h2 id="vault-filter-title">סינון כספת גישות (<?= e(count($items)) ?>)</h2>
        <?php if ($filters['client_id'] || $filters['asset_id'] || $filters['q']): ?>
            <a class="btn btn-ghost btn-sm" href="/vault">איפוס סינון</a>
        <?php endif; ?>
    </div>
    <p class="muted small">סיסמאות וסודות מוצפנים ב-AES-256-GCM ולעולם אינם מוצגים בטבלה. חשיפת סוד דורשת הרשאת <code>vault.reveal</code> ונרשמת ביומן האירועים הבלתי-מחיק (<code>vault.secret_revealed</code>).</p>
    <form method="get" action="/vault" class="form form-inline" id="vault-filter-form">
        <label class="field">
            <span>לקוח</span>
            <select name="client_id" id="vault-filter-client">
                <option value="">כל הלקוחות</option>
                <?php foreach ($clients as $cl): ?>
                    <option value="<?= e((int) $cl['id']) ?>" <?= (string) ($filters['client_id'] ?? '') === (string) $cl['id'] ? 'selected' : '' ?>><?= e($cl['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>נכס / דומיין</span>
            <select name="asset_id" id="vault-filter-asset">
                <option value="">כל הנכסים</option>
                <?php foreach ($assetsList as $as): ?>
                    <option value="<?= e((int) $as['id']) ?>" <?= (string) ($filters['asset_id'] ?? '') === (string) $as['id'] ? 'selected' : '' ?>><?= e($as['domain'] ?? ('#' . (int) $as['id'])) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field field-wide">
            <span>חיפוש (כותרת / שירות / משתמש / לקוח / דומיין)</span>
            <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="wp-admin, cPanel, SSH...">
        </label>
        <button type="submit" class="btn btn-primary">סינון</button>
    </form>
</section>

<?php if ($canCreate): ?>
<section class="card" id="new" aria-labelledby="new-vault-title">
    <h2 id="new-vault-title">הוספת גישה חדשה לכספת</h2>
    <form method="post" action="/vault" class="form form-grid" id="vault-create-form" novalidate>
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
            <span>קישור לנכס / דומיין (רשות)</span>
            <select name="asset_id">
                <option value="">ללא נכס ספציפי</option>
                <?php foreach ($assetsList as $as): ?>
                    <option value="<?= e((int) $as['id']) ?>" <?= ($old['asset_id'] ?? '') === (string) $as['id'] ? 'selected' : '' ?>><?= e($as['domain'] ?? ('#' . (int) $as['id'])) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['asset_id'])): ?><small class="field-error" id="err-asset_id"><?= e($errors['asset_id']) ?></small><?php endif; ?>
        </label>
        <label class="field">
            <span>כותרת *</span>
            <input type="text" name="title" value="<?= e($old['title'] ?? '') ?>" required maxlength="150" placeholder="גישת ניהול WordPress">
            <?php if (isset($errors['title'])): ?><small class="field-error" id="err-title"><?= e($errors['title']) ?></small><?php endif; ?>
        </label>
        <label class="field">
            <span>שירות / מערכת</span>
            <input type="text" name="service" value="<?= e($old['service'] ?? '') ?>" maxlength="100" placeholder="WordPress / cPanel / Cloudflare">
        </label>
        <label class="field">
            <span>כתובת התחברות (URL)</span>
            <input type="url" name="url" dir="ltr" value="<?= e($old['url'] ?? '') ?>" maxlength="500" placeholder="https://example.co.il/wp-admin">
        </label>
        <label class="field">
            <span>שם משתמש</span>
            <input type="text" name="username" dir="ltr" value="<?= e($old['username'] ?? '') ?>" maxlength="150" autocomplete="off">
        </label>
        <label class="field">
            <span>סיסמה / סוד (יוצפן ב-AES-256-GCM)</span>
            <input type="password" name="secret" dir="ltr" maxlength="1000" autocomplete="new-password" placeholder="השאירו ריק לשמירת מטא-דאטה בלבד">
            <?php if (isset($errors['secret'])): ?><small class="field-error" id="err-secret"><?= e($errors['secret']) ?></small><?php endif; ?>
        </label>
        <label class="field field-wide">
            <span>הערות פנימיות</span>
            <input type="text" name="notes" value="<?= e($old['notes'] ?? '') ?>" maxlength="2000">
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="vault-create-submit">הצפנה ושמירה בכספת</button>
        </div>
    </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="vault-list-title">
    <h2 id="vault-list-title">רשומות כספת (<?= e(count($items)) ?>)</h2>
    <?php if ($items === []): ?>
        <p class="empty">אין רשומות כספת התואמות לסינון.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" id="vault-list-table">
                <thead>
                    <tr>
                        <th scope="col">כותרת</th>
                        <th scope="col">לקוח</th>
                        <th scope="col">נכס מקושר</th>
                        <th scope="col">שירות / URL</th>
                        <th scope="col">שם משתמש</th>
                        <th scope="col">מצב סוד</th>
                        <th scope="col">חשיפות ביומן</th>
                        <th scope="col">פעולות</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $v): ?>
                        <?php $vid = (int) $v['id']; ?>
                        <tr id="vault-row-<?= e($vid) ?>">
                            <td class="strong"><a href="/vault/<?= e($vid) ?>"><?= e($v['title'] ?? ('#' . $vid)) ?></a></td>
                            <td>
                                <?php if ($v['client_id'] !== null): ?>
                                    <a href="/clients/<?= e((int) $v['client_id']) ?>?tab=access"><?= e($v['client_name'] ?? ('#' . (int) $v['client_id'])) ?></a>
                                <?php else: ?>
                                    <span class="muted">לא משויך</span>
                                <?php endif; ?>
                            </td>
                            <td dir="ltr">
                                <?php if ($v['asset_id'] !== null): ?>
                                    <a href="/assets/<?= e((int) $v['asset_id']) ?>"><?= e($v['asset_domain'] ?? ('#' . (int) $v['asset_id'])) ?></a>
                                <?php else: ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= e($v['service'] ?? '') ?>
                                <?php if (!empty($v['url'])): ?><div class="muted small" dir="ltr"><?= e($v['url']) ?></div><?php endif; ?>
                            </td>
                            <td dir="ltr" class="ltr-cell"><?= e($v['username'] ?? '—') ?></td>
                            <td>
                                <?php if ((int) $v['has_secret'] === 1 && $v['encryption'] === 'aes-256-gcm'): ?>
                                    <span class="level level-ok">מוצפן AES-256-GCM</span>
                                <?php elseif ((int) $v['has_secret'] === 1): ?>
                                    <span class="muted">קיים במקור (לא יובא)</span>
                                <?php else: ?>
                                    <span class="muted">ללא סוד</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge"><?= e((int) ($v['reveal_count'] ?? 0)) ?></span></td>
                            <td>
                                <a class="btn btn-ghost btn-sm" href="/vault/<?= e($vid) ?>">פרטים ויומן</a>
                                <?php if ($canReveal && $v['encryption'] === 'aes-256-gcm'): ?>
                                    <form method="post" action="/vault/<?= e($vid) ?>/reveal" class="inline-form">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="btn btn-ghost btn-sm" id="reveal-btn-<?= e($vid) ?>">🔓 חשיפת סוד</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
