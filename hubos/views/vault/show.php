<?php
use HubOS\Core\Csrf;

/**
 * @var array<string, mixed> $item
 * @var string|null $revealedSecret
 * @var bool $canUpdate
 * @var bool $canReveal
 * @var bool $hasVaultKey
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $assetsList
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$id = (int) $item['id'];
$actionLabels = [
    'vault.secret_revealed' => '🔓 סוד נחשף (vault.secret_revealed)',
    'vault.created'         => 'נוצרה רשומת כספת',
    'vault.updated'         => 'עודכנה רשומת כספת',
];
?>
<section class="card" aria-labelledby="vault-item-title">
    <div class="card-head">
        <div>
            <p class="eyebrow">
                <a href="/vault">← כל הכספת</a>
                <?php if ($item['client_id'] !== null): ?> · <a href="/clients/<?= e((int) $item['client_id']) ?>?tab=access"><?= e($item['client_name'] ?? '') ?></a><?php endif; ?>
                <?php if ($item['asset_id'] !== null): ?> · נכס: <a href="/assets/<?= e((int) $item['asset_id']) ?>" dir="ltr"><?= e($item['asset_domain'] ?? ('#' . (int) $item['asset_id'])) ?></a><?php endif; ?>
            </p>
            <h2 id="vault-item-title"><?= e($item['title'] ?? ('#' . $id)) ?></h2>
        </div>
        <?php if ($canReveal && ($item['encryption'] ?? '') === 'aes-256-gcm'): ?>
            <form method="post" action="/vault/<?= e($id) ?>/reveal" id="vault-reveal-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-primary" id="vault-reveal-btn">🔓 חשיפת סוד מוצפן (נרשם ביומן)</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (isset($errors['secret'])): ?>
        <div class="alert alert-danger" role="alert" id="err-secret"><?= e($errors['secret']) ?></div>
    <?php endif; ?>

    <?php if ($revealedSecret !== null): ?>
        <div class="alert alert-success" role="status" id="revealed-secret-box">
            <strong>הסוד פוענח בהצלחה (החשיפה נרשמה ביומן האירועים כ-<code>vault.secret_revealed</code>):</strong>
            <div class="ltr-cell" dir="ltr"><code id="revealed-secret-value"><?= e($revealedSecret) ?></code></div>
        </div>
    <?php endif; ?>

    <div class="grid-2">
        <div>
            <p><strong>לקוח:</strong> <?= $item['client_id'] !== null ? '<a href="/clients/' . e((int) $item['client_id']) . '?tab=access">' . e($item['client_name'] ?? '') . '</a>' : '—' ?></p>
            <p><strong>נכס / דומיין מקושר:</strong> <?= $item['asset_id'] !== null ? '<a href="/assets/' . e((int) $item['asset_id']) . '" dir="ltr">' . e($item['asset_domain'] ?? ('#' . (int) $item['asset_id'])) . '</a>' : '—' ?></p>
            <p><strong>שירות:</strong> <?= e($item['service'] ?? '—') ?></p>
        </div>
        <div>
            <p><strong>כתובת URL:</strong> <span dir="ltr"><?= e($item['url'] ?? '—') ?></span></p>
            <p><strong>שם משתמש:</strong> <span dir="ltr" id="vault-item-username"><?= e($item['username'] ?? '—') ?></span></p>
            <p><strong>הצפנה:</strong> <code><?= e($item['encryption']) ?></code></p>
        </div>
    </div>

    <?php if ($canUpdate): ?>
        <h3>עריכת רשומת כספת / החלפת סוד</h3>
        <form method="post" action="/vault/<?= e($id) ?>" class="form form-grid" id="vault-edit-form" novalidate>
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
                <span>קישור לנכס / דומיין</span>
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
                <input type="text" name="title" value="<?= e($old['title'] ?? '') ?>" required maxlength="150">
                <?php if (isset($errors['title'])): ?><small class="field-error" id="err-title"><?= e($errors['title']) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>שירות</span>
                <input type="text" name="service" value="<?= e($old['service'] ?? '') ?>" maxlength="100">
            </label>
            <label class="field">
                <span>כתובת URL</span>
                <input type="url" name="url" dir="ltr" value="<?= e($old['url'] ?? '') ?>" maxlength="500">
            </label>
            <label class="field">
                <span>שם משתמש</span>
                <input type="text" name="username" dir="ltr" value="<?= e($old['username'] ?? '') ?>" maxlength="150">
            </label>
            <label class="field">
                <span>סיסמה חדשה (השאירו ריק לשמירת הקיימת)</span>
                <input type="password" name="secret" dir="ltr" maxlength="1000" autocomplete="new-password">
            </label>
            <label class="field">
                <span>סטטוס</span>
                <input type="text" name="status" value="<?= e($old['status'] ?? 'active') ?>" maxlength="40">
            </label>
            <label class="field field-wide">
                <span>הערות פנימיות</span>
                <input type="text" name="notes" value="<?= e($old['notes'] ?? '') ?>" maxlength="2000">
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="vault-update-submit">שמירת שינויים</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="card" id="vault-audit-log" aria-labelledby="vault-audit-title">
    <h3 id="vault-audit-title">יומן ביקורת גישות לרשומה זו (<?= e(count($item['audit_log'] ?? [])) ?>)</h3>
    <?php if (empty($item['audit_log'])): ?>
        <p class="empty">טרם נרשמו פעולות חשיפה או עדכון לרשומה זו.</p>
    <?php else: ?>
        <ol class="timeline" id="vault-audit-list">
            <?php foreach ($item['audit_log'] as $ev): ?>
                <li class="timeline-item">
                    <span class="dot" aria-hidden="true"></span>
                    <div>
                        <strong><?= e($actionLabels[$ev['action']] ?? $ev['action']) ?></strong>
                        <span class="muted">· בוצע ע"י <?= e($ev['actor_name'] ?? 'מערכת') ?></span>
                        <time class="muted" datetime="<?= e($ev['created_at']) ?>"><?= e(fmt_dt($ev['created_at'])) ?></time>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
