<?php
use HubOS\Core\Csrf;

/**
 * @var array<string, mixed> $entry
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $tasks
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$id = (int) $entry['id'];
$err = static fn (string $k): string => isset($errors[$k]) ? '<small class="field-error">' . e($errors[$k]) . '</small>' : '';
?>
<section class="card" aria-labelledby="edit-title">
    <div class="card-head">
        <h2 id="edit-title">עריכת רישום זמן #<?= e($id) ?></h2>
        <a href="/time" class="btn btn-ghost btn-sm">← חזרה</a>
    </div>
    <p class="muted small">מקור: <?= e($entry['source'] ?? '—') ?> · כל שינוי נרשם ביומן האירועים עם ערכים לפני/אחרי.</p>
    <form method="post" action="/time/entries/<?= e($id) ?>" class="form form-inline" id="time-edit-form" novalidate>
        <?= Csrf::field() ?>
        <label class="field"><span>לקוח *</span>
            <select name="client_id" data-role="client-select" required>
                <option value="">בחרו לקוח…</option>
                <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select><?= $err('client_id') ?>
        </label>
        <label class="field"><span>משימה</span>
            <select name="task_id" data-role="task-select">
                <option value="">ללא</option>
                <?php foreach ($tasks as $t): ?><option value="<?= e((int) $t['id']) ?>" data-client="<?= e((int) $t['client_id']) ?>" <?= ($old['task_id'] ?? '') === (string) $t['id'] ? 'selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
            </select><?= $err('task_id') ?>
        </label>
        <input type="hidden" name="project_id" value="<?= e($old['project_id'] ?? '') ?>">
        <label class="field"><span>תאריך</span><input type="date" name="entry_date" value="<?= e($old['entry_date'] ?? '') ?>"><?= $err('entry_date') ?></label>
        <label class="field"><span>משך *</span><input type="text" name="duration" value="<?= e($old['duration'] ?? '') ?>" dir="ltr" required><?= $err('duration') ?></label>
        <label class="field field-wide"><span>תיאור *</span><input type="text" name="description" value="<?= e($old['description'] ?? '') ?>" maxlength="500" required><?= $err('description') ?></label>
        <button type="submit" class="btn btn-primary" id="time-edit-submit">שמירה</button>
    </form>
    <form method="post" action="/time/entries/<?= e($id) ?>/delete" class="inline-form danger-zone">
        <?= Csrf::field() ?><input type="hidden" name="_return" value="/time">
        <button type="submit" class="btn btn-ghost btn-sm" id="time-delete">מחיקת הרישום</button>
    </form>
</section>
