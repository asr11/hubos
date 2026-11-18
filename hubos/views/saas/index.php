<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Saas\SaasService;

/**
 * @var list<array<string, mixed>> $tenants
 * @var bool $publicSignupEnabled
 */
?>
<section class="stats" aria-label="סיכום ארגוני SaaS">
    <article class="stat card">
        <span class="stat-label">ארגונים פעילים בפלטפורמה</span>
        <strong class="stat-value" id="stat-saas-tenants"><?= e(count($tenants)) ?></strong>
        <span class="stat-hint">בידוד מלא לפי tenant_id</span>
    </article>
    <article class="stat card">
        <span class="stat-label">הרשמת ניסיון עצמאית (/signup)</span>
        <strong class="stat-value" id="stat-saas-signup"><?= $publicSignupEnabled ? 'פתוחה' : 'נעולה' ?></strong>
        <span class="stat-hint">בשליטת בעלי הפלטפורמה</span>
    </article>
</section>

<section class="card" aria-labelledby="saas-signup-gate-title">
    <div class="card-head">
        <h2 id="saas-signup-gate-title">שער הרשמת SaaS ציבורית (/signup)</h2>
        <form method="post" action="/settings/saas/signup-toggle" class="inline-form">
            <?= Csrf::field() ?>
            <input type="hidden" name="enabled" value="<?= $publicSignupEnabled ? '0' : '1' ?>">
            <button type="submit" class="btn <?= $publicSignupEnabled ? 'btn-ghost' : 'btn-primary' ?> btn-sm" id="btn-toggle-signup">
                <?= $publicSignupEnabled ? '🔒 נעילת הרשמה ציבורית' : '🚀 פתיחת הרשמת ניסיון 14 יום (/signup)' ?>
            </button>
        </form>
    </div>
    <p class="muted">כאשר השער פתוח, עסקים וסוכנויות יכולים להקים לעצמם סביבת ניסיון מבודדת בכתובת <code dir="ltr">https://os.hub.co.il/signup</code> ללא שום גישה לנתוני ארגונים אחרים.</p>
</section>

<section class="card" aria-labelledby="new-tenant-title">
    <details open>
        <summary class="card-head cursor-pointer">
            <h2 id="new-tenant-title">+ פתיחת סביבת ארגון (Tenant) חדשה ללקוח SaaS</h2>
            <span class="muted">מקים מרחב נתונים מבודד ב-100% + משתמש בעלים לארגון</span>
        </summary>
        <form method="post" action="/settings/saas/tenants" class="form-grid mt-sm" id="new-tenant-form">
            <?= Csrf::field() ?>
            <label class="field">
                <span>שם החברה / הסוכנות <abbr title="שדה חובה">*</abbr></span>
                <input type="text" name="company_name" required maxlength="160" placeholder="לדוגמה: אלפא דיגיטל בע״מ">
            </label>
            <label class="field">
                <span>מזהה ארגון באנגלית (Slug) <abbr title="שדה חובה">*</abbr></span>
                <input type="text" name="slug" required maxlength="63" dir="ltr" pattern="[a-z0-9][a-z0-9\-]{1,62}" placeholder="alpha-digital">
            </label>
            <label class="field">
                <span>שם מלא של בעלי הארגון <abbr title="שדה חובה">*</abbr></span>
                <input type="text" name="owner_name" required maxlength="120" placeholder="ישראל ישראלי">
            </label>
            <label class="field">
                <span>אימייל כניסה לבעלים <abbr title="שדה חובה">*</abbr></span>
                <input type="email" name="owner_email" required maxlength="254" dir="ltr" placeholder="ceo@alpha.co.il">
            </label>
            <label class="field">
                <span>סיסמה ראשונית (לפחות 12 תווים) <abbr title="שדה חובה">*</abbr></span>
                <input type="password" name="owner_password" required minlength="12" maxlength="1024" dir="ltr">
            </label>
            <label class="field">
                <span>חבילת רישוי</span>
                <select name="plan">
                    <?php foreach (SaasService::PLANS as $pKey => $pLabel): ?>
                        <option value="<?= e($pKey) ?>"><?= e($pLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>קישור סליקה / הוראת קבע (אופציונלי)</span>
                <input type="url" name="billing_url" maxlength="500" dir="ltr" placeholder="https://mrng.to/...">
            </label>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="tenant-create-submit">הקמת ארגון SaaS מבודד</button>
            </div>
        </form>
    </details>
</section>

<section class="card" aria-labelledby="tenants-list-title">
    <div class="card-head">
        <h2 id="tenants-list-title">כל הארגונים ב-OS HUB (<?= e(count($tenants)) ?>)</h2>
    </div>
    <div class="table-wrap">
        <table class="table" id="tenants-table">
            <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">שם ארגון</th>
                    <th scope="col">Slug</th>
                    <th scope="col">משתמשים / לקוחות</th>
                    <th scope="col">חיבור Morning</th>
                    <th scope="col">חבילה</th>
                    <th scope="col">סטטוס</th>
                    <th scope="col">ניהול מנוי</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tenants as $t): $tid = (int) $t['id']; ?>
                <tr id="tenant-row-<?= e($tid) ?>">
                    <td dir="ltr">#<?= e($tid) ?></td>
                    <td><strong><?= e($t['name']) ?></strong></td>
                    <td dir="ltr"><code><?= e($t['slug']) ?></code></td>
                    <td dir="ltr"><?= e((int) $t['users_count']) ?> / <?= e((int) $t['clients_count']) ?></td>
                    <td>
                        <span class="pill <?= !empty($t['morning_ready']) ? 'pill-ok' : 'pill-warn' ?>">
                            <?= !empty($t['morning_ready']) ? 'מוצפן ומוגדר' : 'טרם הוגדר' ?>
                        </span>
                    </td>
                    <td><span class="pill"><?= e($t['plan_label']) ?></span></td>
                    <td>
                        <span class="pill <?= $t['status'] === 'active' ? 'pill-ok' : 'pill-danger' ?>">
                            <?= $t['status'] === 'active' ? 'פעיל' : 'מושעה' ?>
                        </span>
                    </td>
                    <td>
                        <details>
                            <summary class="btn btn-ghost btn-sm">עריכת חבילה</summary>
                            <form method="post" action="/settings/saas/tenants/<?= e($tid) ?>" class="stack mt-sm">
                                <?= Csrf::field() ?>
                                <label class="field">
                                    <span>חבילה</span>
                                    <select name="plan">
                                        <?php foreach (SaasService::PLANS as $pKey => $pLabel): ?>
                                            <option value="<?= e($pKey) ?>" <?= $t['plan'] === $pKey ? 'selected' : '' ?>><?= e($pLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label class="field">
                                    <span>סטטוס</span>
                                    <select name="status" <?= $tid === 1 ? 'disabled' : '' ?>>
                                        <option value="active" <?= $t['status'] === 'active' ? 'selected' : '' ?>>פעיל</option>
                                        <option value="suspended" <?= $t['status'] === 'suspended' ? 'selected' : '' ?>>מושעה</option>
                                    </select>
                                    <?php if ($tid === 1): ?><input type="hidden" name="status" value="active"><?php endif; ?>
                                </label>
                                <label class="field">
                                    <span>קישור סליקה</span>
                                    <input type="url" name="billing_url" value="<?= e($t['billing_url'] ?? '') ?>" dir="ltr" placeholder="https://mrng.to/...">
                                </label>
                                <button type="submit" class="btn btn-primary btn-sm">שמירה</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
