<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Work\TaskService;

/**
 * @var list<array<string, mixed>> $templates
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $users
 */
$err = static fn (string $k): string => isset($errors[$k]) ? '<small class="field-error">' . e($errors[$k]) . '</small>' : '';
?>
<section class="card" aria-labelledby="rec-new-title">
    <div class="card-head"><h2 id="rec-new-title">תבנית משימה חודשית</h2><span class="muted small">נוצרת אוטומטית פעם בחודש: <code dir="ltr">php bin/recurring.php</code></span></div>
    <form method="post" action="/work/recurring" class="form form-inline" id="recurring-form" novalidate>
        <?= Csrf::field() ?>
        <label class="field field-wide"><span>כותרת *</span><input type="text" name="title" value="<?= e($old['title'] ?? '') ?>" required maxlength="200" placeholder="גיבוי ועדכוני וורדפרס"><?= $err('title') ?></label>
        <label class="field"><span>לקוח * (חובה)</span>
            <select name="client_id" required><option value="">בחרו לקוח…</option>
                <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select><?= $err('client_id') ?></label>
        <label class="field"><span>יום בחודש (1–28)</span><input type="number" name="day_of_month" min="1" max="28" value="<?= e($old['day_of_month'] ?? '1') ?>"><?= $err('day_of_month') ?></label>
        <label class="field"><span>עדיפות</span><select name="priority"><?php foreach (TaskService::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($old['priority'] ?? 'normal') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>אחראי</span><select name="assignee_user_id"><option value="">ללא</option><?php foreach ($users as $u): ?><option value="<?= e((int) $u['id']) ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
        <label class="field check-field"><span>נראות</span><span class="check"><input type="checkbox" name="client_visible" value="1"> גלוי ללקוח</span></label>
        <button type="submit" class="btn btn-primary">שמירת תבנית</button>
    </form>
</section>
<section class="card" aria-labelledby="rec-list-title">
    <div class="card-head"><h2 id="rec-list-title">תבניות</h2><span class="badge"><?= e(count($templates)) ?></span></div>
    <?php if ($templates === []): ?><p class="empty">אין תבניות.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="recurring-list">
        <thead><tr><th scope="col">תבנית</th><th scope="col">לקוח</th><th scope="col">יום</th><th scope="col">מצב</th><th scope="col"></th></tr></thead>
        <tbody><?php foreach ($templates as $t): ?>
            <tr><td class="strong"><?= e($t['title']) ?></td>
                <td><a class="client-link" href="/clients/<?= e((int) $t['client_id']) ?>?tab=work"><?= e($t['client_name'] ?? '') ?></a></td>
                <td><?= e((int) $t['day_of_month']) ?></td>
                <td><?= (int) $t['active'] === 1 ? 'פעילה' : '<span class="muted">מושבתת</span>' ?></td>
                <td><form method="post" action="/work/recurring/<?= e((int) $t['id']) ?>/active" class="inline-form"><?= Csrf::field() ?>
                    <input type="hidden" name="active" value="<?= (int) $t['active'] === 1 ? '0' : '1' ?>">
                    <button type="submit" class="btn btn-ghost btn-sm"><?= (int) $t['active'] === 1 ? 'השבתה' : 'הפעלה' ?></button></form></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
