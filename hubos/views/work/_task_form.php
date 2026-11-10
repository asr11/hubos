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
<form method="post" action="<?= e($formAction) ?>" class="task-form-modern" id="<?= e($formId) ?>" novalidate>
    <?= Csrf::field() ?>
    <?php if (!empty($returnTo)): ?><input type="hidden" name="_return" value="<?= e($returnTo) ?>"><?php endif; ?>

    <div class="task-presets-bar">
        <span class="task-presets-label">⚡ מילוי מהיר:</span>
        <button type="button" class="btn btn-ghost btn-sm btn-preset"
                data-preset-title="הוספת קופון הנחה לחנות"
                data-preset-desc="יצירת קופון הנחה במערכת בהתאם לבקשת הלקוח, הגדרת אחוזי הנחה/סכום ובדיקת תקינות בסל הקניות."
                data-preset-priority="normal">🏷️ הוספת קופון</button>
        <button type="button" class="btn btn-ghost btn-sm btn-preset"
                data-preset-title="אופטימיזציית מהירות וביצועים לאתר"
                data-preset-desc="ניתוח מדדי Core Web Vitals (LCP, CLS, INP), כיווץ תמונות, ניקוי מטמון ושיפור זמני טעינה במובייל."
                data-preset-priority="high">🚀 אופטימיזציה לאתר</button>
        <button type="button" class="btn btn-ghost btn-sm btn-preset"
                data-preset-title="תחזוקה ובדיקת מערכות שוטפת"
                data-preset-desc="בדיקת גיבויים שוטפים, בדיקת תקינות טפסים, עדכוני תוספים ובדיקת אבטחה."
                data-preset-priority="normal">🛠️ תחזוקה שוטפת</button>
    </div>

    <div>
        <label class="task-form-label" for="f-title-<?= e($formId) ?>">כותרת המשימה *</label>
        <input type="text" name="title" id="f-title-<?= e($formId) ?>" class="task-form-control" value="<?= e($old['title'] ?? '') ?>" required maxlength="300"
               placeholder="לדוגמה: בניית תיק לקוח, שדרוג תוסף, אופטימיזציה..."
               <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>><?= $err('title') ?>
    </div>

    <div class="task-form-grid-2">
        <div>
            <label class="task-form-label" for="f-client-<?= e($formId) ?>">תיק לקוח * (חובה)</label>
            <select name="client_id" id="f-client-<?= e($formId) ?>" data-role="client-select" class="task-form-control" required
                    <?= isset($errors['client_id']) ? 'aria-invalid="true"' : '' ?>>
                <option value="">בחרו לקוח מהרשימה…</option>
                <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select><?= $err('client_id') ?>
        </div>
        <div>
            <label class="task-form-label" for="f-project-<?= e($formId) ?>">שיוך לפרויקט</label>
            <select name="project_id" id="f-project-<?= e($formId) ?>" data-role="task-select" class="task-form-control">
                <option value="">ללא פרויקט (משימה כללית)</option>
                <?php foreach ($projectsList as $p): ?><option value="<?= e((int) $p['id']) ?>" data-client="<?= e((int) $p['client_id']) ?>" <?= ($old['project_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select><?= $err('project_id') ?>
        </div>
    </div>

    <div class="task-form-grid-4">
        <div>
            <label class="task-form-label" for="f-status-<?= e($formId) ?>">סטטוס</label>
            <select name="status" id="f-status-<?= e($formId) ?>" class="task-form-control">
                <?php foreach (TaskService::STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $curStatus === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select><?= $err('status') ?>
        </div>
        <div>
            <label class="task-form-label" for="f-priority-<?= e($formId) ?>">עדיפות</label>
            <select name="priority" id="f-priority-<?= e($formId) ?>" class="task-form-control">
                <?php foreach (TaskService::PRIORITIES as $k => $label): ?><option value="<?= e($k) ?>" <?= ($old['priority'] ?? 'normal') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="task-form-label" for="f-due-<?= e($formId) ?>">תאריך יעד</label>
            <input type="date" name="due_at" id="f-due-<?= e($formId) ?>" class="task-form-control" value="<?= e($old['due_at'] ?? '') ?>"><?= $err('due_at') ?>
        </div>
        <div>
            <label class="task-form-label" for="f-assignee-<?= e($formId) ?>">אחראי לביצוע</label>
            <select name="assignee_user_id" id="f-assignee-<?= e($formId) ?>" class="task-form-control">
                <option value="">ללא הקצאה</option>
                <?php foreach ($users as $u): ?><option value="<?= e((int) $u['id']) ?>" <?= ($old['assignee_user_id'] ?? '') === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select><?= $err('assignee_user_id') ?>
        </div>
    </div>

    <div class="task-visibility-box">
        <input type="checkbox" name="client_visible" id="f-client-visible-<?= e($formId) ?>" value="1" <?= ($old['client_visible'] ?? '') === '1' ? 'checked' : '' ?>>
        <label for="f-client-visible-<?= e($formId) ?>">גלוי ללקוח בפורטל הלקוחות</label>
    </div>

    <div>
        <label class="task-form-label" for="f-desc-<?= e($formId) ?>">תיאור ופרטים נוספים</label>
        <textarea name="description" id="f-desc-<?= e($formId) ?>" rows="5" maxlength="5000" class="task-form-control"
                  placeholder="פרטו את מטרת המשימה, קישורים נדרשים או הנחיות עבודה..."><?= e($old['description'] ?? '') ?></textarea>
    </div>

    <div class="task-submit-row">
        <button type="submit" class="btn btn-primary btn-lg-submit"><?= e($submitLabel) ?></button>
    </div>
</form>

