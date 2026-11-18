<?php
use HubOS\Core\Csrf;

/**
 * @var string|null $error
 * @var array<string, string>|null $old
 */
$old = $old ?? [];
?>
<section class="auth-card card" aria-labelledby="signup-title">
    <div class="brand-lockup">
        <span class="brand-mark" aria-hidden="true">OS</span>
        <div>
            <h1 id="signup-title">פתיחת סביבת OS HUB חדשה</h1>
            <p class="muted">14 ימי ניסיון · סביבת נתונים ריבונית ומבודדת לעסק שלך</p>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/signup" class="stack" id="saas-signup-form">
        <?= Csrf::field() ?>
        <label class="field">
            <span>שם החברה / הסוכנות <abbr title="שדה חובה">*</abbr></span>
            <input type="text" name="company_name" value="<?= e($old['company_name'] ?? '') ?>" required maxlength="160" autocomplete="organization">
        </label>
        <label class="field">
            <span>מזהה באנגלית (לדוגמה: my-agency) <abbr title="שדה חובה">*</abbr></span>
            <input type="text" name="slug" value="<?= e($old['slug'] ?? '') ?>" required maxlength="63" dir="ltr" pattern="[a-z0-9][a-z0-9\-]{1,62}">
        </label>
        <label class="field">
            <span>השם המלא שלך <abbr title="שדה חובה">*</abbr></span>
            <input type="text" name="owner_name" value="<?= e($old['owner_name'] ?? '') ?>" required maxlength="120" autocomplete="name">
        </label>
        <label class="field">
            <span>אימייל מנהל ראשי <abbr title="שדה חובה">*</abbr></span>
            <input type="email" name="owner_email" value="<?= e($old['owner_email'] ?? '') ?>" required maxlength="254" dir="ltr" autocomplete="email">
        </label>
        <label class="field">
            <span>סיסמה (לפחות 12 תווים) <abbr title="שדה חובה">*</abbr></span>
            <input type="password" name="owner_password" required minlength="12" maxlength="1024" dir="ltr" autocomplete="new-password">
        </label>
        <button type="submit" class="btn btn-primary btn-block" id="signup-submit">פתיחת סביבת ניסיון מיידית</button>
    </form>
    <p class="muted mt-sm"><a href="/login">כבר רשום? מעבר להתחברות ←</a></p>
</section>
