<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Work\TaskService;

/**
 * Task form (create + edit). Included from work/tasks and work/task.
 *
 * @var string $formAction
 * @var string $formId
 * @var string $submitLabel
 * @var array<string, string> $old
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $projectsList
 * @var list<array<string, mixed>> $users
 */
$err = static fn (string $k): string => isset($errors[$k]) ? '<small class="field-error" id="err-' . e($k) . '">' . e($errors[$k]) . '</small>' : '';
$curStatus = TaskService::statusKey($old['status'] ?? 'todo');
?>
<form method="post" action="<?= e($formAction) ?>" class="form form-inline" id="<?= e($formId) ?>" novalidate>
    <?= Csrf::field() ?>
    <?php if (!empty($returnTo)): ?><input type="hidden" name="_return" value="<?= e($returnTo) ?>"><?php endif; ?>
    <label class="field field-wide"><span>כותרת *</span>
        <input type="text" name="title" value="<?= e($old['title'] ?? '') ?>" required maxlength="300" <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>><?= $err('title') ?>
    </label>
    <label class="field"><span>לקוח * (חובה)</span>
        <select name="client_id" data-role="client-select" required <?= isset($errors['client_id']) ? 'aria-invalid="true"' : '' ?>>
            <option value="">בחרו לקוח…</option>
            <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select><?= $err('client_id') ?>
    </label>
    <label class="field"><span>פרויקט</span>
        <select name="project_id" data-role="task-select">
            <option value="">ללא</option>
            <?php foreach ($projectsList as $p): ?><option value="<?= e((int) $p['id']) ?>" data-client="<?= e((int) $p['client_id']) ?>" <?= ($old['project_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select><?= $err('project_id') ?>
    </label>
    <label class="field"><span>סטטוס</span>
        <select name="status">
            <?php foreach (TaskService::STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $curStatus === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select><?= $err('status') ?>
    </label>
    <label class="field"><span>עדיפות</span>
        <select name="priority">
            <?php foreach (TaskService::PRIORITIES as $k => $label): ?><option value="<?= e($k) ?>" <?= ($old['priority'] ?? 'normal') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field"><span>תאריך יעד</span><input type="date" name="due_at" value="<?= e($old['due_at'] ?? '') ?>"><?= $err('due_at') ?></label>
    <label class="field"><span>אחראי</span>
        <select name="assignee_user_id">
            <option value="">ללא</option>
            <?php foreach ($users as $u): ?><option value="<?= e((int) $u['id']) ?>" <?= ($old['assignee_user_id'] ?? '') === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
        </select><?= $err('assignee_user_id') ?>
    </label>
    <label class="field check-field"><span>נראות</span>
        <span class="check"><input type="checkbox" name="client_visible" value="1" <?= ($old['client_visible'] ?? '') === '1' ? 'checked' : '' ?>> גלוי ללקוח</span>
    </label>
    <label class="field field-wide"><span>תיאור</span><textarea name="description" rows="3" maxlength="5000"><?= e($old['description'] ?? '') ?></textarea></label>
    <button type="submit" class="btn btn-primary"><?= e($submitLabel) ?></button>
</form>
