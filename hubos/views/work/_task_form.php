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
<form method="post" action="<?= e($formAction) ?>" class="task-form-modern" id="<?= e($formId) ?>" novalidate style="display:flex;flex-direction:column;gap:1.25rem;">
    <?= Csrf::field() ?>
    <?php if (!empty($returnTo)): ?><input type="hidden" name="_return" value="<?= e($returnTo) ?>"><?php endif; ?>

    <!-- Quick Presets -->
    <div style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;background:#f8fafc;padding:0.75rem 1rem;border-radius:8px;border:1px solid #e2e8f0;">
        <span style="font-weight:700;font-size:0.85rem;color:#475569;">⚡ מילוי מהיר:</span>
        <button type="button" class="btn btn-ghost btn-sm" onclick="hubFillTask_<?= preg_replace('/[^a-zA-Z0-9_]/', '_', $formId) ?>('הוספת קופון הנחה לחנות', 'יצירת קופון הנחה במערכת בהתאם לבקשת הלקוח, הגדרת אחוזי הנחה/סכום ובדיקת תקינות בסל הקניות.', 'normal')" style="background:#ffffff;border-color:#cbd5e1;">🏷️ הוספת קופון</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="hubFillTask_<?= preg_replace('/[^a-zA-Z0-9_]/', '_', $formId) ?>('אופטימיזציית מהירות וביצועים לאתר', 'ניתוח מדדי Core Web Vitals (LCP, CLS, INP), כיווץ תמונות, ניקוי מטמון ושיפור זמני טעינה במובייל.', 'high')" style="background:#ffffff;border-color:#cbd5e1;">🚀 אופטימיזציה לאתר</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="hubFillTask_<?= preg_replace('/[^a-zA-Z0-9_]/', '_', $formId) ?>('תחזוקה ובדיקת מערכות שוטפת', 'בדיקת גיבויים שוטפים, בדיקת תקינות טפסים, עדכוני תוספים ובדיקת אבטחה.', 'normal')" style="background:#ffffff;border-color:#cbd5e1;">🛠️ תחזוקה שוטפת</button>
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

    <!-- Row 1: Title -->
    <div>
        <label style="display:block;font-size:0.88rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">כותרת המשימה *</label>
        <input type="text" name="title" value="<?= e($old['title'] ?? '') ?>" required maxlength="300"
               style="width:100%;padding:0.75rem 1rem;font-size:1rem;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;"
               placeholder="לדוגמה: בניית תיק לקוח, שדרוג תוסף, אופטימיזציה..."
               <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>><?= $err('title') ?>
    </div>

    <!-- Row 2: Client & Project (2 columns) -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:1rem;">
        <div>
            <label style="display:block;font-size:0.88rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">🏢 לקוח * (חובה)</label>
            <select name="client_id" data-role="client-select" required
                    style="width:100%;padding:0.75rem 1rem;font-size:0.95rem;border:1px solid #cbd5e1;border-radius:6px;background:#ffffff;"
                    <?= isset($errors['client_id']) ? 'aria-invalid="true"' : '' ?>>
                <option value="">בחרו לקוח מהרשימה…</option>
                <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select><?= $err('client_id') ?>
        </div>
        <div>
            <label style="display:block;font-size:0.88rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">📁 שיוך לפרויקט</label>
            <select name="project_id" data-role="task-select"
                    style="width:100%;padding:0.75rem 1rem;font-size:0.95rem;border:1px solid #cbd5e1;border-radius:6px;background:#ffffff;">
                <option value="">ללא פרויקט (משימה כללית)</option>
                <?php foreach ($projectsList as $p): ?><option value="<?= e((int) $p['id']) ?>" data-client="<?= e((int) $p['client_id']) ?>" <?= ($old['project_id'] ?? '') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select><?= $err('project_id') ?>
        </div>
    </div>

    <!-- Row 3: Status, Priority, Due Date, Assignee (4 columns) -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:1rem;">
        <div>
            <label style="display:block;font-size:0.88rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">📊 סטטוס</label>
            <select name="status" style="width:100%;padding:0.75rem 1rem;font-size:0.95rem;border:1px solid #cbd5e1;border-radius:6px;background:#ffffff;">
                <?php foreach (TaskService::STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $curStatus === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select><?= $err('status') ?>
        </div>
        <div>
            <label style="display:block;font-size:0.88rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">⚡ עדיפות</label>
            <select name="priority" style="width:100%;padding:0.75rem 1rem;font-size:0.95rem;border:1px solid #cbd5e1;border-radius:6px;background:#ffffff;">
                <?php foreach (TaskService::PRIORITIES as $k => $label): ?><option value="<?= e($k) ?>" <?= ($old['priority'] ?? 'normal') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:0.88rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">📅 תאריך יעד</label>
            <input type="date" name="due_at" value="<?= e($old['due_at'] ?? '') ?>"
                   style="width:100%;padding:0.75rem 1rem;font-size:0.95rem;border:1px solid #cbd5e1;border-radius:6px;background:#ffffff;box-sizing:border-box;"><?= $err('due_at') ?>
        </div>
        <div>
            <label style="display:block;font-size:0.88rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">👤 אחראי לביצוע</label>
            <select name="assignee_user_id" style="width:100%;padding:0.75rem 1rem;font-size:0.95rem;border:1px solid #cbd5e1;border-radius:6px;background:#ffffff;">
                <option value="">ללא הקצאה</option>
                <?php foreach ($users as $u): ?><option value="<?= e((int) $u['id']) ?>" <?= ($old['assignee_user_id'] ?? '') === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select><?= $err('assignee_user_id') ?>
        </div>
    </div>

    <!-- Row 4: Client Visibility -->
    <div style="background:#f8fafc;padding:0.75rem 1rem;border-radius:6px;border:1px solid #e2e8f0;display:inline-flex;align-items:center;gap:0.6rem;width:fit-content;">
        <input type="checkbox" name="client_visible" id="f-client-visible-<?= e($formId) ?>" value="1" <?= ($old['client_visible'] ?? '') === '1' ? 'checked' : '' ?> style="width:1.15rem;height:1.15rem;accent-color:var(--primary);cursor:pointer;">
        <label for="f-client-visible-<?= e($formId) ?>" style="font-size:0.92rem;font-weight:600;color:#334155;cursor:pointer;">👁️ גלוי ללקוח בפורטל הלקוחות (Client Portal)</label>
    </div>

    <!-- Row 5: Description -->
    <div>
        <label style="display:block;font-size:0.88rem;font-weight:700;color:#334155;margin-bottom:0.4rem;">📝 תיאור ופרטים נוספים</label>
        <textarea name="description" rows="4" maxlength="5000"
                  style="width:100%;padding:0.75rem 1rem;font-size:0.95rem;line-height:1.6;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;"
                  placeholder="פרטו את מטרת המשימה, קישורים נדרשים או הנחיות עבודה..."><?= e($old['description'] ?? '') ?></textarea>
    </div>

    <!-- Row 6: Submit -->
    <div style="display:flex;gap:0.75rem;align-items:center;margin-top:0.5rem;">
        <button type="submit" class="btn btn-primary" style="padding:0.75rem 2rem;font-size:1rem;font-weight:700;"><?= e($submitLabel) ?></button>
    </div>
</form>
