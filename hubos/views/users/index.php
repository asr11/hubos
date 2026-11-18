<?php
use HubOS\Core\Csrf;
use HubOS\Core\Permissions;
use HubOS\Modules\Users\UserService;

/**
 * @var \HubOS\Core\Context $ctx
 * @var list<array<string, mixed>> $users
 * @var list<array{module: string, label: string, enabled: bool}> $modules
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $auditEvents
 */
$canManageUsers = $ctx->can('users.manage');
$canManagePerms = $ctx->can('permissions.manage');
$activeUsers = count(array_filter($users, static fn (array $u): bool => ($u['status'] ?? '') === 'active'));
$viewerUsers = count(array_filter($users, static fn (array $u): bool => ($u['role'] ?? '') === 'client_viewer'));
$enabledModules = count(array_filter($modules, static fn (array $m): bool => $m['enabled']));
?>
<section class="stats" aria-label="סיכום משתמשים והרשאות">
    <article class="stat card">
        <span class="stat-label">משתמשים במערכת</span>
        <strong class="stat-value" id="stat-users-total"><?= e(count($users)) ?></strong>
        <span class="stat-hint">פעילים: <?= e($activeUsers) ?></span>
    </article>
    <article class="stat card">
        <span class="stat-label">צופי לקוח (Client Portal)</span>
        <strong class="stat-value" id="stat-users-viewers"><?= e($viewerUsers) ?></strong>
        <span class="stat-hint">ללא גישה לכספת לעולם</span>
    </article>
    <article class="stat card">
        <span class="stat-label">מודולים פעילים</span>
        <strong class="stat-value" id="stat-modules-enabled"><?= e($enabledModules) ?> / <?= e(count($modules)) ?></strong>
        <span class="stat-hint">רמת התאמה 1 (Tenant)</span>
    </article>
</section>

<?php if ($canManageUsers): ?>
<section class="card" aria-labelledby="new-user-title">
    <details>
        <summary class="card-head cursor-pointer">
            <h2 id="new-user-title">+ הוספת משתמש חדש</h2>
            <span class="muted">בעלים · מנהל · צוות · סוכן · צופה (לקוח)</span>
        </summary>
        <form method="post" action="/settings/users" class="form-grid mt-sm" id="new-user-form">
            <?= Csrf::field() ?>
            <label class="field">
                <span>שם מלא <abbr title="שדה חובה">*</abbr></span>
                <input type="text" name="name" id="user-name" required maxlength="120" placeholder="לדוגמה: דנה כהן">
            </label>
            <label class="field">
                <span>אימייל <abbr title="שדה חובה">*</abbr></span>
                <input type="email" name="email" id="user-email" required maxlength="254" dir="ltr" placeholder="dana@hub.co.il">
            </label>
            <label class="field">
                <span>תפקיד <abbr title="שדה חובה">*</abbr></span>
                <select name="role" id="user-role" required>
                    <?php foreach (Permissions::ROLE_LABELS as $rKey => $rLabel): ?>
                        <?php if ($rKey === 'owner' && ($user['role'] ?? '') !== 'owner') continue; ?>
                        <option value="<?= e($rKey) ?>" <?= $rKey === 'staff' ? 'selected' : '' ?>><?= e($rLabel) ?> (<?= e($rKey) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>שיוך ללקוח (חובה עבור צופה לקוח)</span>
                <select name="client_id" id="user-client">
                    <option value="">— ללא שיוך לקוח —</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= e((int) $c['id']) ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>סיסמה ראשונית (לפחות 12 תווים) <abbr title="שדה חובה">*</abbr></span>
                <input type="password" name="password" id="user-password" required minlength="12" maxlength="1024" dir="ltr">
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="user-create-submit">יצירת משתמש</button>
            </div>
        </form>
    </details>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="users-list-title">
    <div class="card-head">
        <h2 id="users-list-title">משתמשים והרשאות פרטניות (<?= e(count($users)) ?>)</h2>
        <span class="muted">רמת התאמה 2: דריסת הרשאה פרטנית מעל ברירת המחדל של התפקיד</span>
    </div>
    <div class="table-wrap">
        <table class="table" id="users-table">
            <thead>
                <tr>
                    <th scope="col">שם</th>
                    <th scope="col">אימייל</th>
                    <th scope="col">תפקיד</th>
                    <th scope="col">לקוח משויך</th>
                    <th scope="col">סטטוס</th>
                    <th scope="col">התחברות אחרונה</th>
                    <th scope="col">התאמות הרשאה</th>
                    <th scope="col">ניהול</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): $uid = (int) $u['id']; $overrides = $u['overrides'] ?? []; ?>
                <tr id="user-row-<?= e($uid) ?>">
                    <td><strong><?= e($u['name']) ?></strong></td>
                    <td dir="ltr"><?= e($u['email']) ?></td>
                    <td><span class="pill"><?= e(Permissions::ROLE_LABELS[$u['role']] ?? $u['role']) ?></span></td>
                    <td>
                        <?php if (!empty($u['client_id'])): ?>
                            <a href="/clients/<?= e((int) $u['client_id']) ?>"><?= e($u['client_name'] ?? ('#' . (int) $u['client_id'])) ?></a>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="pill <?= $u['status'] === 'active' ? 'pill-ok' : 'pill-warn' ?>">
                            <?= e(UserService::STATUSES[$u['status']] ?? $u['status']) ?>
                        </span>
                    </td>
                    <td dir="ltr" class="muted"><?= e(fmt_dt($u['last_login_at'])) ?></td>
                    <td>
                        <?php if ($overrides === []): ?>
                            <span class="muted">ברירת מחדל לתפקיד</span>
                        <?php else: ?>
                            <?php foreach ($overrides as $permKey => $isGranted): ?>
                                <span class="pill <?= $isGranted ? 'pill-ok' : 'pill-danger' ?>" dir="ltr">
                                    <?= $isGranted ? '+' : '−' ?><?= e($permKey) ?>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($canManageUsers || $canManagePerms): ?>
                            <details>
                                <summary class="btn btn-ghost btn-sm cursor-pointer">עריכה / הרשאות</summary>
                                <div class="card popover-card">
                                    <?php if ($canManageUsers && ($u['role'] !== 'owner' || ($user['role'] ?? '') === 'owner')): ?>
                                    <form method="post" action="/settings/users/<?= e($uid) ?>" class="form-grid">
                                        <?= Csrf::field() ?>
                                        <label class="field">
                                            <span>שם</span>
                                            <input type="text" name="name" value="<?= e($u['name']) ?>" required maxlength="120">
                                        </label>
                                        <label class="field">
                                            <span>תפקיד</span>
                                            <select name="role">
                                                <?php foreach (Permissions::ROLE_LABELS as $rKey => $rLabel): ?>
                                                    <?php if ($rKey === 'owner' && ($user['role'] ?? '') !== 'owner') continue; ?>
                                                    <option value="<?= e($rKey) ?>" <?= $u['role'] === $rKey ? 'selected' : '' ?>><?= e($rLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>סטטוס</span>
                                            <select name="status">
                                                <?php foreach (UserService::STATUSES as $sKey => $sLabel): ?>
                                                    <option value="<?= e($sKey) ?>" <?= $u['status'] === $sKey ? 'selected' : '' ?>><?= e($sLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>שיוך ללקוח</span>
                                            <select name="client_id">
                                                <option value="">— ללא —</option>
                                                <?php foreach ($clients as $c): ?>
                                                    <option value="<?= e((int) $c['id']) ?>" <?= (int) ($u['client_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>סיסמה חדשה (השאר ריק ללא שינוי)</span>
                                            <input type="password" name="password" minlength="12" maxlength="1024" dir="ltr">
                                        </label>
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-primary btn-sm">שמירת משתמש</button>
                                        </div>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($canManagePerms && ($u['role'] !== 'owner' || ($user['role'] ?? '') === 'owner')): ?>
                                    <hr class="divider-line">
                                    <form method="post" action="/settings/users/<?= e($uid) ?>/permissions" class="form-grid">
                                        <?= Csrf::field() ?>
                                        <label class="field">
                                            <span>הרשאה פרטנית</span>
                                            <select name="permission" required>
                                                <?php foreach (Permissions::ALL_PERMISSIONS as $pKey => $pLabel): ?>
                                                    <option value="<?= e($pKey) ?>"><?= e($pLabel) ?> (<?= e($pKey) ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="field">
                                            <span>פעולה</span>
                                            <select name="state" required>
                                                <option value="grant">אפשר (+ דריסה חיובית)</option>
                                                <option value="revoke">חסום (− שלילה מפורשת)</option>
                                                <option value="clear">איפוס לברירת מחדל של התפקיד</option>
                                            </select>
                                        </label>
                                        <div class="form-actions">
                                            <button type="submit" class="btn btn-ghost btn-sm">עדכון הרשאה</button>
                                        </div>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php
$partners = array_values(array_filter($users, static fn (array $u): bool => ($u['role'] ?? '') === 'partner' && ($u['status'] ?? '') === 'active'));
$isOwner = ($user['role'] ?? '') === 'owner';
$tMeta = $tenantMeta ?? [];
?>
<?php if ($canManageUsers): ?>
<section class="grid-2-wide">
    <article class="card" aria-labelledby="partner-assign-title">
        <div class="card-head">
            <h2 id="partner-assign-title">שיוך לקוח לשותף / סוכן מכירות (תמלוגים)</h2>
        </div>
        <?php if ($partners === []): ?>
            <p class="empty">צור תחילה משתמש בתפקיד "שותף / סוכן מכירות" למעלה כדי לשייך אליו לקוחות.</p>
        <?php else: ?>
            <form method="post" action="/settings/partners/assign-client" class="stack" id="partner-assign-form">
                <?= Csrf::field() ?>
                <label class="field">
                    <span>בחר תיק לקוח</span>
                    <select name="client_id" required>
                        <option value="">— בחר לקוח —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= e((int) $c['id']) ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>שייך לשותף מכירות</span>
                    <select name="partner_user_id">
                        <option value="">— ללא שותף (הסר שיוך) —</option>
                        <?php foreach ($partners as $p): ?>
                            <option value="<?= e((int) $p['id']) ?>"><?= e($p['name']) ?> (<?= e(number_format((float) ($p['commission_pct'] ?? 10), 0)) ?>%)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-sm">שמור שיוך שותף</button>
                </div>
            </form>
        <?php endif; ?>
    </article>

    <?php if ($isOwner): ?>
    <article class="card" aria-labelledby="morning-integration-title">
        <div class="card-head">
            <h2 id="morning-integration-title">חיבור Morning לארגון (קריאה בלבד · מוצפן) ומנוי</h2>
            <span class="pill <?= !empty($tMeta['morning_configured']) ? 'pill-ok' : 'pill-warn' ?>">
                <?= !empty($tMeta['morning_configured']) ? 'מחובר (' . e((string) $tMeta['morning_key_id_masked']) . ')' : 'טרם הוגדר מפתח ארגוני' ?>
            </span>
        </div>
        <p class="muted">חבילת ארגון נוכחית: <strong><?= e((string) ($tMeta['plan_label'] ?? 'Enterprise')) ?></strong>
            <?php if (!empty($tMeta['billing_url'])): ?>
                · <a href="<?= e((string) $tMeta['billing_url']) ?>" target="_blank" rel="noopener noreferrer">ניהול הוראת קבע / מנוי ↗</a>
            <?php endif; ?>
            <?php if (!empty($isPlatformOwner)): ?>
                · <a href="/settings/saas">ניהול כלל ארגוני SaaS ←</a>
            <?php endif; ?>
        </p>
        <form method="post" action="/settings/integrations/morning" class="stack mt-sm" id="morning-settings-form">
            <?= Csrf::field() ?>
            <label class="field">
                <span>Morning API Key ID <abbr title="שדה חובה">*</abbr></span>
                <input type="text" name="morning_key_id" required maxlength="120" dir="ltr" placeholder="מזהה מפתח מחשבונית ירוקה">
            </label>
            <label class="field">
                <span>Morning API Secret (מוצפן ב-AES-256-GCM) <abbr title="שדה חובה">*</abbr></span>
                <input type="password" name="morning_key_secret" required maxlength="250" dir="ltr" autocomplete="off">
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm" id="morning-save-submit">הצפן ושמור מפתח Morning לארגון</button>
            </div>
        </form>
    </article>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="grid-2-wide">
    <article class="card" aria-labelledby="modules-title">
        <div class="card-head">
            <h2 id="modules-title">מודולים פעילים בחברה (Tenant Modules)</h2>
        </div>
        <table class="table" id="modules-table">
            <thead>
                <tr>
                    <th scope="col">מודול</th>
                    <th scope="col">תיאור</th>
                    <th scope="col">סטטוס</th>
                    <?php if ($canManagePerms): ?><th scope="col">פעולה</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($modules as $m): ?>
                <tr>
                    <td dir="ltr"><code><?= e($m['module']) ?></code></td>
                    <td><?= e($m['label']) ?></td>
                    <td>
                        <span class="pill <?= $m['enabled'] ? 'pill-ok' : 'pill-warn' ?>">
                            <?= $m['enabled'] ? 'פעיל' : 'כבוי' ?>
                        </span>
                    </td>
                    <?php if ($canManagePerms): ?>
                    <td>
                        <form method="post" action="/settings/modules" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="module" value="<?= e($m['module']) ?>">
                            <input type="hidden" name="enabled" value="<?= $m['enabled'] ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-ghost btn-sm">
                                <?= $m['enabled'] ? 'כיבוי מודול' : 'הפעלת מודול' ?>
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </article>

    <article class="card" aria-labelledby="users-audit-title">
        <div class="card-head">
            <h2 id="users-audit-title">יומן שינויי משתמשים והרשאות</h2>
            <span class="muted">append-only</span>
        </div>
        <?php if ($auditEvents === []): ?>
            <p class="empty">אין אירועי הרשאות להצגה.</p>
        <?php else: ?>
            <ol class="timeline">
                <?php foreach ($auditEvents as $ev): ?>
                    <li class="timeline-item">
                        <span class="dot" aria-hidden="true"></span>
                        <div>
                            <strong><?= e($ev['action']) ?></strong>
                            <span class="muted">· <?= e($ev['actor_name'] ?? 'מערכת') ?></span>
                        </div>
                        <time class="muted" datetime="<?= e($ev['created_at']) ?>" dir="ltr"><?= e(fmt_dt($ev['created_at'])) ?></time>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </article>
</section>
