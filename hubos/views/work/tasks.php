<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Work\TaskService;

/**
 * @var list<array<string, mixed>> $tasks
 * @var array<string, string|null> $filters
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var bool $internal
 * @var bool $canCreate
 * @var bool $canArchive
 * @var list<array<string, mixed>> $clients
 */
$archivedView = ($filters['archived'] ?? '') === '1';
?>
<?php if ($canCreate): ?>
<section class="card" aria-labelledby="new-task-title" id="new">
    <div class="card-head"><h2 id="new-task-title">משימה חדשה</h2><span class="muted small">שיוך ללקוח הוא חובה</span></div>
    <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert" id="task-errors"><?= e(implode(' · ', $errors)) ?></div><?php endif; ?>
    <?php $formAction = '/tasks'; $formId = 'task-create-form'; $submitLabel = 'יצירת משימה'; $returnTo = null;
    include __DIR__ . '/_task_form.php'; ?>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="tasks-title">
    <div class="card-head">
        <h2 id="tasks-title"><?= $archivedView ? 'משימות בארכיון' : 'משימות' ?></h2>
        <span class="badge" id="tasks-count"><?= e(count($tasks)) ?></span>
    </div>
    <?php if ($internal): ?>
    <form method="get" action="/tasks" class="form form-inline search-form" id="task-filter-form">
        <label class="field"><span>חיפוש</span><input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" maxlength="100"></label>
        <label class="field"><span>סטטוס</span>
            <select name="status"><option value="">הכל</option>
                <?php foreach (TaskService::STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span>לקוח</span>
            <select name="client_id"><option value="">כולם</option>
                <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($filters['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span>תצוגה</span>
            <select name="archived"><option value="">פעילות</option><option value="1" <?= $archivedView ? 'selected' : '' ?>>ארכיון</option></select></label>
        <button type="submit" class="btn btn-ghost">סינון</button>
    </form>
    <?php endif; ?>
    <?php if ($tasks === []): ?><p class="empty">אין משימות.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="tasks-list">
        <thead><tr><th scope="col">משימה</th><th scope="col">לקוח</th><th scope="col">פרויקט</th><th scope="col">סטטוס</th><th scope="col">עדיפות</th><th scope="col">יעד</th>
            <?php if ($internal): ?><th scope="col">אחראי</th><th scope="col">נראות</th><th scope="col"></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($tasks as $t): $sk = TaskService::statusKey((string) $t['status']); ?>
            <tr class="task-row" id="task-<?= e((int) $t['id']) ?>">
                <td class="strong"><a href="/tasks/<?= e((int) $t['id']) ?>"><?= e($t['title']) ?></a></td>
                <td><?php if ($t['client_id'] !== null): ?><a class="client-link" href="/clients/<?= e((int) $t['client_id']) ?>?tab=work"><?= e($t['client_name'] ?? '') ?></a><?php else: ?><span class="level level-warn80">ממתין לשיוך</span><?php endif; ?></td>
                <td><?= e($t['project_name'] ?? '') ?></td>
                <td><span class="tstatus tstatus-<?= e($sk) ?>"><?= e(TaskService::STATUSES[$sk] ?? $t['status']) ?></span></td>
                <td><?= e(TaskService::PRIORITIES[$t['priority']] ?? $t['priority']) ?></td>
                <td><?= e(fmt_date($t['due_at'] ?? null)) ?></td>
                <?php if ($internal): ?>
                <td><?= e($t['assignee_name'] ?? '') ?></td>
                <td><?= (int) $t['client_visible'] === 1 ? '<span class="visible-flag">גלוי ללקוח</span>' : '<span class="muted">פנימי</span>' ?></td>
                <td class="row-actions">
                    <?php if ($t['client_id'] !== null && $ctx->can('time.track')): ?>
                    <form method="post" action="/timer/start" class="inline-form"><?= Csrf::field() ?>
                        <input type="hidden" name="task_id" value="<?= e((int) $t['id']) ?>"><input type="hidden" name="_return" value="/tasks">
                        <button type="submit" class="btn btn-ghost btn-sm" title="הפעלת טיימר על המשימה">▶</button></form>
                    <?php endif; ?>
                    <?php if ($canArchive): ?>
                    <form method="post" action="/tasks/<?= e((int) $t['id']) ?>/archive" class="inline-form"><?= Csrf::field() ?>
                        <input type="hidden" name="archive" value="<?= $archivedView ? '0' : '1' ?>"><input type="hidden" name="_return" value="/tasks">
                        <button type="submit" class="btn btn-ghost btn-sm"><?= $archivedView ? 'שחזור' : 'ארכיון' ?></button></form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
