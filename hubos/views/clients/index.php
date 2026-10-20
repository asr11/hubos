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

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem;">
    <div>
        <h2 style="font-size:1.5rem;font-weight:800;color:var(--text);margin-bottom:0.25rem;">
            <?= ($q ?? '') !== '' ? 'תוצאות חיפוש' : (($statusFilter ?? 'active') === 'active' ? 'לקוחות פעילים' : (($statusFilter ?? '') === 'inactive' ? 'לקוחות בארכיון / לא פעילים' : 'כל הלקוחות')) ?>
        </h2>
        <span class="muted small"><?= e(count($clients)) ?> לקוחות מוצגים</span>
    </div>
    <?php if ($canCreate): ?>
        <button type="button" class="btn btn-primary" onclick="var el=document.getElementById('sec-new-client'); el.toggleAttribute('open'); if(el.hasAttribute('open')) document.getElementById('client-name').focus();" id="btn-toggle-new-client">
            <span>+ הוספת לקוח חדש</span>
        </button>
    <?php endif; ?>
</div>

<?php if ($canCreate): ?>
<details class="card" id="sec-new-client" <?= !empty($errors) ? 'open' : '' ?> style="margin-bottom:1.5rem;border:1px solid var(--border-strong);">
    <summary style="cursor:pointer;font-weight:700;color:var(--primary);padding:0.25rem 0;">טופס הוספת לקוח חדש ▼</summary>
    <div style="padding-top:1rem;margin-top:0.75rem;border-top:1px solid var(--border);">
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
            <button type="submit" class="btn btn-primary" id="client-create-submit">שמירת לקוח</button>
        </form>
    </div>
</details>
<?php endif; ?>

<section class="card" aria-labelledby="clients-title">
    <div class="tabs" style="margin-bottom: 1.25rem;" id="client-status-tabs">
        <a href="/clients?status=active<?= ($q ?? '') !== '' ? '&q=' . urlencode($q) : '' ?>"
           class="tab <?= ($statusFilter ?? 'active') === 'active' ? 'is-active' : '' ?>">
            פעילים <span class="badge"><?= e($counts['active'] ?? 0) ?></span>
        </a>
        <a href="/clients?status=inactive<?= ($q ?? '') !== '' ? '&q=' . urlencode($q) : '' ?>"
           class="tab <?= ($statusFilter ?? '') === 'inactive' ? 'is-active' : '' ?>">
            ארכיון / לא פעילים <span class="badge"><?= e($counts['inactive'] ?? 0) ?></span>
        </a>
        <a href="/clients?status=all<?= ($q ?? '') !== '' ? '&q=' . urlencode($q) : '' ?>"
           class="tab <?= ($statusFilter ?? '') === 'all' ? 'is-active' : '' ?>">
            הכל <span class="badge"><?= e($counts['all'] ?? 0) ?></span>
        </a>
    </div>

    <form method="get" action="/clients" class="form form-inline search-form" role="search" id="client-search-form" style="margin-bottom:1.25rem">
        <input type="hidden" name="status" value="<?= e($statusFilter ?? 'active') ?>">
        <label class="field" style="flex:1">
            <span>חיפוש מהיר</span>
            <input type="search" name="q" id="client-search" value="<?= e($q ?? '') ?>" maxlength="100"
                   placeholder="חיפוש לפי שם לקוח, ח.פ, טלפון, אימייל או עיר...">
        </label>
        <button type="submit" class="btn btn-primary" id="client-search-submit">חיפוש</button>
        <?php if (($q ?? '') !== ''): ?><a class="btn btn-ghost" href="/clients?status=<?= e($statusFilter ?? 'active') ?>" id="client-search-clear">ניקוי</a><?php endif; ?>
    </form>

    <?php if ($clients === []): ?>
        <p class="empty"><?= ($q ?? '') !== '' ? 'לא נמצאו לקוחות תואמים לחיפוש.' : 'עדיין אין לקוחות בקטגוריה זו.' ?></p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table" id="clients-table">
                <thead>
                <tr>
                    <th scope="col">שם הלקוח</th>
                    <th scope="col">ח.פ / ע.מ</th>
                    <th scope="col">טלפון</th>
                    <th scope="col">אימייל</th>
                    <th scope="col">סטטוס</th>
                    <th scope="col">תאריך הצטרפות</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $c): ?>
                    <tr>
                        <td class="strong">
                            <a class="client-link" href="/clients/<?= e((int) $c['id']) ?>" style="font-weight:700;font-size:1.02rem;">
                                <?= e($c['name']) ?>
                            </a>
                        </td>
                        <td dir="ltr" class="ltr-cell"><?= e($c['tax_id'] ?? '—') ?></td>
                        <td dir="ltr" class="ltr-cell"><?= !empty($c['phone']) ? '<a href="tel:' . e($c['phone']) . '">' . e($c['phone']) . '</a>' : '—' ?></td>
                        <td dir="ltr" class="ltr-cell"><?= !empty($c['email']) ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '—' ?></td>
                        <td><span class="status status-<?= e($c['status']) ?>"><?= e($statusLabels[$c['status']] ?? $c['status']) ?></span></td>
                        <td class="muted"><?= e(fmt_dt($c['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
