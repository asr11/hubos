<?php
use HubOS\Core\Csrf;

/**
 * @var string $email
 * @var string|null $error
 */
?>
<section class="auth-card" aria-labelledby="login-title">
    <div class="auth-logo" aria-hidden="true">OS</div>
    <h1 id="login-title" class="auth-title">כניסה ל-OS HUB</h1>
    <p class="auth-sub">מערכת ההפעלה של האב מערכות מתקדמות</p>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert" id="login-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="form" id="login-form" novalidate>
        <?= Csrf::field() ?>
        <label class="field">
            <span>אימייל</span>
            <input type="email" name="email" id="login-email" value="<?= e($email) ?>"
                   autocomplete="username" inputmode="email" dir="ltr" required maxlength="254" autofocus>
        </label>
        <label class="field">
            <span>סיסמה</span>
            <input type="password" name="password" id="login-password"
                   autocomplete="current-password" dir="ltr" required maxlength="1024">
        </label>
        <button type="submit" class="btn btn-primary btn-block" id="login-submit">כניסה</button>
    </form>
    <p class="auth-foot">חיבור מאובטח · כל פעולה נרשמת ביומן</p>
</section>
