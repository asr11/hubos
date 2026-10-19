<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Work\TaskService;

/**
 * @var array<string, mixed> $task
 * @var array<string, mixed>|null $client
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var bool $internal
 * @var bool $canUpdate
 * @var bool $canArchive
 */
$id = (int) $task['id'];
$sk = TaskService::statusKey((string) $task['status']);
?>
<section class="card" aria-labelledby="task-title">
    <div class="card-head">
        <h2 id="task-title"><?= e($task['title']) ?></h2>
        <a href="/tasks" class="btn btn-ghost btn-sm">← כל המשימות</a>
    </div>
    <p class="client-meta">
        <span>לקוח: <?php if ($client !== null): ?><a class="client-link" href="/clients/<?= e((int) $client['id']) ?>?tab=work" id="task-client"><?= e($client['name']) ?></a><?php else: ?><span class="level level-warn80">ממתין לשיוך</span><?php endif; ?></span>
        <span>סטטוס: <?= e(TaskService::STATUSES[$sk] ?? $task['status']) ?></span>
        <span>עדיפות: <?= e(TaskService::PRIORITIES[$task['priority']] ?? $task['priority']) ?></span>
        <?php if (!empty($task['due_at'])): ?><span>יעד: <?= e(fmt_date($task['due_at'])) ?></span><?php endif; ?>
        <?php if ($task['archived_at'] !== null): ?><span class="level level-warn80">בארכיון</span><?php endif; ?>
        <?php if ($task['recurring_period'] !== null): ?><span class="muted">משימה חודשית (<?= e($task['recurring_period']) ?>)</span><?php endif; ?>
    </p>
    <?php if (!empty($task['description'])): ?><p class="pre"><?= e($task['description']) ?></p><?php endif; ?>
    <?php if ($internal && $client !== null && $ctx->can('time.track')): ?>
        <form method="post" action="/timer/start" class="inline-form"><?= Csrf::field() ?>
            <input type="hidden" name="task_id" value="<?= e($id) ?>"><input type="hidden" name="_return" value="/tasks/<?= e($id) ?>">
            <button type="submit" class="btn btn-primary btn-sm" id="task-timer-start">▶ טיימר על המשימה</button></form>
    <?php endif; ?>
</section>

<?php if ($internal && $canUpdate): ?>
<section class="card" aria-labelledby="task-edit-title">
    <div class="card-head"><h2 id="task-edit-title">עריכה</h2><span class="muted small">שיוך ללקוח הוא חובה · השינויים נרשמים ביומן האירועים</span></div>
    <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert" id="task-errors"><?= e(implode(' · ', $errors)) ?></div><?php endif; ?>
    <?php $formAction = '/tasks/' . $id; $formId = 'task-edit-form'; $submitLabel = 'שמירה'; $returnTo = null;
    include __DIR__ . '/_task_form.php'; ?>
    <?php if ($canArchive): ?>
    <form method="post" action="/tasks/<?= e($id) ?>/archive" class="inline-form danger-zone"><?= Csrf::field() ?>
        <input type="hidden" name="archive" value="<?= $task['archived_at'] === null ? '1' : '0' ?>"><input type="hidden" name="_return" value="/tasks/<?= e($id) ?>">
        <button type="submit" class="btn btn-ghost btn-sm" id="task-archive"><?= $task['archived_at'] === null ? 'העברה לארכיון' : 'שחזור מהארכיון' ?></button></form>
    <?php endif; ?>
</section>
<?php endif; ?>
