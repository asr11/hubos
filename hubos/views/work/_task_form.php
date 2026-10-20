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
    <div class="field-wide" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin-bottom:0.5rem;background:#f8fafc;padding:0.5rem 0.75rem;border-radius:6px;border:1px solid #e2e8f0;">
        <span class="muted small" style="font-weight:600;">⚡ מילוי מהיר לפי שירות:</span>
        <button type="button" class="btn btn-ghost btn-sm" onclick="hubFillTask_<?= preg_replace('/[^a-zA-Z0-9_]/', '_', $formId) ?>('הוספת קופון הנחה לחנות', 'יצירת קופון הנחה במערכת בהתאם לבקשת הלקוח, הגדרת אחוזי הנחה/סכום ובדיקת תקינות בסל הקניות.', 'normal')">🏷️ הוספת קופון</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="hubFillTask_<?= preg_replace('/[^a-zA-Z0-9_]/', '_', $formId) ?>('אופטימיזציית מהירות וביצועים לאתר', 'ניתוח מדדי Core Web Vitals (LCP, CLS, INP), כיווץ תמונות, ניקוי מטמון ושיפור זמני טעינה במובייל.', 'high')">🚀 אופטימיזציה לאתר</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="hubFillTask_<?= preg_replace('/[^a-zA-Z0-9_]/', '_', $formId) ?>('תחזוקה ובדיקת מערכות שוטפת', 'בדיקת גיבויים שוטפים, בדיקת תקינות טפסים, עדכוני תוספים ובדיקת אבטחה.', 'normal')">🛠️ תחזוקה שוטפת</button>
    </div>
    <script>
    function hubFillTask_<?= preg_replace('/[^a-zA-Z0-9_]/', '_', $formId) ?>(title, desc, priority) {
        var form = document.getElementById('<?= e($formId) ?>');
        if (!form) return;
        var t = form.querySelector('input[name="title"]');
        var d = form.querySelector('textarea[name="description"]');
        var p = form.querySelector('select[name="priority"]');
        if (t) t.value = title;
        if (d) d.value = desc;
        if (p && priority) p.value = priority;
        if (t) t.focus();
    }
    </script>
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
