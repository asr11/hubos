<?php
use HubOS\Core\Csrf;
use HubOS\Core\Provisioning;

/**
 * @var array<string, string> $errors
 */
$err = static fn (string $k): ?string => isset($errors[$k]) ? (string) $errors[$k] : null;
?>
<section class="card" id="account-password-card" aria-labelledby="account-password-title">
    <div class="card-head">
        <h2 id="account-password-title">🔑 החלפת סיסמה</h2>
        <span class="muted">הסיסמה שלך נשמרת מוצפנת (Argon2) ואינה נחשפת לאיש, גם לא לבעלים</span>
    </div>
    <form method="post" action="/account/password" class="form-grid" id="account-password-form" novalidate>
        <?= Csrf::field() ?>
        <label class="field">
            <span>סיסמה נוכחית <abbr title="שדה חובה">*</abbr></span>
            <input type="password" name="current_password" id="pw-current" autocomplete="current-password" dir="ltr" required maxlength="1024" autofocus
                   <?= $err('current_password') ? 'aria-invalid="true" aria-describedby="pw-current-err"' : '' ?>>
            <?php if ($err('current_password')): ?><span class="field-error" id="pw-current-err" role="alert"><?= e($err('current_password')) ?></span><?php endif; ?>
        </label>
        <label class="field">
            <span>סיסמה חדשה (לפחות <?= e(Provisioning::MIN_PASSWORD) ?> תווים) <abbr title="שדה חובה">*</abbr></span>
            <input type="password" name="new_password" id="pw-new" autocomplete="new-password" dir="ltr" required minlength="<?= e(Provisioning::MIN_PASSWORD) ?>" maxlength="1024"
                   <?= $err('new_password') ? 'aria-invalid="true" aria-describedby="pw-new-err"' : '' ?>>
            <?php if ($err('new_password')): ?><span class="field-error" id="pw-new-err" role="alert"><?= e($err('new_password')) ?></span><?php endif; ?>
        </label>
        <label class="field">
            <span>אישור סיסמה חדשה <abbr title="שדה חובה">*</abbr></span>
            <input type="password" name="confirm_password" id="pw-confirm" autocomplete="new-password" dir="ltr" required maxlength="1024"
                   <?= $err('confirm_password') ? 'aria-invalid="true" aria-describedby="pw-confirm-err"' : '' ?>>
            <?php if ($err('confirm_password')): ?><span class="field-error" id="pw-confirm-err" role="alert"><?= e($err('confirm_password')) ?></span><?php endif; ?>
        </label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="pw-submit">עדכון סיסמה</button>
            <a class="btn btn-ghost" href="/dashboard">ביטול</a>
        </div>
    </form>
</section>
