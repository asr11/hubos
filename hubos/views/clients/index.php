<?php
use HubOS\Core\Csrf;

/**
 * @var list<array<string, mixed>> $clients
 * @var bool $canCreate
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$statusLabels = ['active' => 'פעיל', 'inactive' => 'לא פעיל', 'archived' => 'בארכיון'];
?>
<?php if ($canCreate): ?>
<section class="card" aria-labelledby="new-client-title">
    <div class="card-head">
        <h2 id="new-client-title">לקוח חדש</h2>
    </div>
    <form method="post" action="/clients" class="form form-inline" id="client-create-form" novalidate>
        <?= Csrf::field() ?>
        <label class="field">
            <span>שם *</span>
            <input type="text" name="name" id="client-name" value="<?= e($old['name'] ?? '') ?>" required maxlength="200"
                   <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['name'])): ?><small class="field-error"><?= e($errors['name']) ?></small><?php endif; ?>
        </label>
        <label class="field">
            <span>אימייל</span>
            <input type="email" name="email" id="client-email" value="<?= e($old['email'] ?? '') ?>" dir="ltr" maxlength="254"
                   <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['email'])): ?><small class="field-error"><?= e($errors['email']) ?></small><?php endif; ?>
        </label>
        <label class="field">
            <span>טלפון</span>
            <input type="tel" name="phone" id="client-phone" value="<?= e($old['phone'] ?? '') ?>" dir="ltr" maxlength="25"
                   <?= isset($errors['phone']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['phone'])): ?><small class="field-error"><?= e($errors['phone']) ?></small><?php endif; ?>
        </label>
        <label class="field">
            <span>בנק שעות ראשוני</span>
            <input type="number" step="0.5" min="0" max="1000" name="initial_hours" id="client-initial-hours" value="<?= e($old['initial_hours'] ?? '') ?>" placeholder="0">
            <?php if (isset($errors['initial_hours'])): ?><small class="field-error"><?= e($errors['initial_hours']) ?></small><?php endif; ?>
        </label>
        <label class="field">
            <span>סוג בנק</span>
            <select name="bank_type" id="client-bank-type">
                <option value="one_time" <?= ($old['bank_type'] ?? '') === 'one_time' ? 'selected' : '' ?>>חד-פעמי</option>
                <option value="monthly" <?= ($old['bank_type'] ?? '') === 'monthly' ? 'selected' : '' ?>>ריטיינר חודשי</option>
            </select>
        </label>
        <label class="field">
            <span>מקור פנייה</span>
            <input type="text" name="source" id="client-source" value="<?= e($old['source'] ?? '') ?>" placeholder="טלפון / וואטסאפ" maxlength="100">
        </label>
        <button type="submit" class="btn btn-primary" id="client-create-submit">הוספת לקוח</button>
    </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="clients-title">
    <div class="card-head">
        <h2 id="clients-title"><?= ($q ?? '') !== '' ? 'תוצאות חיפוש' : 'כל הלקוחות' ?></h2>
        <span class="badge" id="clients-count"><?= e(count($clients)) ?></span>
    </div>
    <form method="get" action="/clients" class="form form-inline search-form" role="search" id="client-search-form">
        <label class="field">
            <span>חיפוש</span>
            <input type="search" name="q" id="client-search" value="<?= e($q ?? '') ?>" maxlength="100"
                   placeholder="שם, ח.פ, טלפון, אימייל, עיר או דומיין">
        </label>
        <button type="submit" class="btn btn-primary" id="client-search-submit">חיפוש</button>
        <?php if (($q ?? '') !== ''): ?><a class="btn btn-ghost" href="/clients" id="client-search-clear">ניקוי</a><?php endif; ?>
    </form>
    <?php if ($clients === []): ?>
        <p class="empty"><?= ($q ?? '') !== '' ? 'לא נמצאו לקוחות תואמים.' : 'עדיין אין לקוחות.' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" id="clients-table">
                <thead>
                <tr><th scope="col">שם</th><th scope="col">ח.פ</th><th scope="col">אימייל</th><th scope="col">טלפון</th><th scope="col">סטטוס</th><th scope="col">נוצר</th></tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <tr>
                        <td class="strong"><a class="client-link" href="/clients/<?= e((int) $c['id']) ?>"><?= e($c['name']) ?></a></td>
                        <td dir="ltr" class="ltr-cell"><?= e($c['tax_id'] ?? '') ?></td>
                        <td dir="ltr" class="ltr-cell"><?= e($c['email'] ?? '') ?></td>
                        <td dir="ltr" class="ltr-cell"><?= e($c['phone'] ?? '') ?></td>
                        <td><span class="status status-<?= e($c['status']) ?>"><?= e($statusLabels[$c['status']] ?? $c['status']) ?></span></td>
                        <td class="muted"><?= e(fmt_dt($c['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
