<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Work\ProjectService;
use HubOS\Modules\Work\TaskService;

/**
 * @var array<string, mixed> $project
 * @var list<array<string, mixed>> $tasks
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var bool $internal
 * @var bool $canUpdate
 * @var bool $canArchive
 * @var list<array<string, mixed>> $clients
 */
$id = (int) $project['id'];
$err = static fn (string $k): string => isset($errors[$k]) ? '<small class="field-error">' . e($errors[$k]) . '</small>' : '';
?>
<section class="card" aria-labelledby="project-title">
    <div class="card-head"><h2 id="project-title"><?= e($project['name']) ?></h2><a href="/projects" class="btn btn-ghost btn-sm">← כל הפרויקטים</a></div>
    <p class="client-meta">
        <span>סטטוס: <?= e(ProjectService::STATUSES[$project['status']] ?? $project['status']) ?></span>
        <?php if ($project['client_id'] !== null): ?><span>לקוח: <a class="client-link" href="/clients/<?= e((int) $project['client_id']) ?>?tab=work"><?= e(($ctx->tenant()->find('clients', (int) $project['client_id']) ?? [])['name'] ?? '') ?></a></span><?php else: ?><span class="level level-warn80">ממתין לשיוך</span><?php endif; ?>
        <?php if ($project['archived_at'] !== null): ?><span class="level level-warn80">בארכיון</span><?php endif; ?>
    </p>
    <?php if (!empty($project['description'])): ?><p class="pre"><?= e($project['description']) ?></p><?php endif; ?>
    <h3>משימות בפרויקט (<?= e(count($tasks)) ?>)</h3>
    <?php if ($tasks === []): ?><p class="empty">אין משימות.</p><?php else: ?>
    <ul class="list"><?php foreach ($tasks as $t): ?><li><a href="/tasks/<?= e((int) $t['id']) ?>"><?= e($t['title']) ?></a> <span class="muted">· <?= e(TaskService::STATUSES[TaskService::statusKey((string) $t['status'])] ?? $t['status']) ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<?php if ($internal && $canUpdate): ?>
<section class="card" aria-labelledby="project-edit-title">
    <div class="card-head"><h2 id="project-edit-title">עריכה</h2><span class="muted small">שיוך ללקוח הוא חובה</span></div>
    <form method="post" action="/projects/<?= e($id) ?>" class="form form-inline" id="project-edit-form" novalidate>
        <?= Csrf::field() ?>
        <label class="field"><span>שם *</span><input type="text" name="name" value="<?= e($old['name'] ?? '') ?>" required maxlength="200"><?= $err('name') ?></label>
        <label class="field"><span>לקוח * (חובה)</span>
            <select name="client_id" required>
                <option value="">בחרו לקוח…</option>
                <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select><?= $err('client_id') ?>
        </label>
        <label class="field"><span>סטטוס</span><select name="status"><?php foreach (ProjectService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($old['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field check-field"><span>נראות</span><span class="check"><input type="checkbox" name="client_visible" value="1" <?= ($old['client_visible'] ?? '') === '1' ? 'checked' : '' ?>> גלוי ללקוח</span></label>
        <label class="field field-wide"><span>תיאור</span><textarea name="description" rows="2" maxlength="5000"><?= e($old['description'] ?? '') ?></textarea></label>
        <button type="submit" class="btn btn-primary">שמירה</button>
    </form>
    <?php if ($canArchive): ?>
    <form method="post" action="/projects/<?= e($id) ?>/archive" class="inline-form danger-zone"><?= Csrf::field() ?>
        <input type="hidden" name="archive" value="<?= $project['archived_at'] === null ? '1' : '0' ?>"><input type="hidden" name="_return" value="/projects/<?= e($id) ?>">
        <button type="submit" class="btn btn-ghost btn-sm"><?= $project['archived_at'] === null ? 'העברה לארכיון' : 'שחזור מהארכיון' ?></button></form>
    <?php endif; ?>
</section>
<?php endif; ?>
