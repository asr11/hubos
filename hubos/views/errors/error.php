<?php
/**
 * @var int $status
 * @var string $message
 */
?>
<section class="auth-card error-card" aria-labelledby="error-title">
    <div class="error-code" aria-hidden="true"><?= e($status) ?></div>
    <h1 id="error-title" class="auth-title"><?= e($message) ?></h1>
    <p class="auth-sub">קוד שגיאה <?= e($status) ?></p>
    <a class="btn btn-primary" href="/" id="error-home">חזרה לדף הבית</a>
</section>
