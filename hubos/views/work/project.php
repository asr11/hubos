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
$clientRow = $project['client_id'] !== null ? $ctx->tenant()->find('clients', (int) $project['client_id']) : null;
$totalTasks = count($tasks);
$doneTasks = count(array_filter($tasks, static fn (array $t): bool => TaskService::statusKey((string) $t['status']) === 'done'));
$pctDone = $totalTasks > 0 ? (int) round(($doneTasks / $totalTasks) * 100) : 0;
$widthBucket = match (true) {
    $pctDone >= 95 => 'w-100',
    $pctDone >= 70 => 'w-75',
    $pctDone >= 60 => 'w-67',
    $pctDone >= 45 => 'w-50',
    $pctDone >= 30 => 'w-33',
    $pctDone > 0   => 'w-25',
    default        => 'w-0',
};
?>
<section class="card task-overview-card" aria-labelledby="project-title">
    <div class="task-header-bar">
        <div class="task-header-main">
            <div class="task-badges-row">
                <span class="pstatus pstatus-<?= e((string) $project['status']) ?>"><?= e(ProjectService::STATUSES[$project['status']] ?? $project['status']) ?></span>
                <?php if ($internal): ?>
                    <?php if ((int) ($project['client_visible'] ?? 0) === 1): ?>
                        <span class="visible-flag">👁 גלוי ללקוח בפורטל</span>
                    <?php else: ?>
                        <span class="badge">🔒 פנימי</span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($project['archived_at'] !== null): ?>
                    <span class="level level-warn80">בארכיון</span>
                <?php endif; ?>
            </div>
            <h2 id="project-title" class="task-main-title"><?= fmt_inline_bidi((string) $project['name']) ?></h2>
        </div>
        <div class="task-header-actions">
            <?php if ($internal && $canUpdate): ?>
                <button type="button" class="btn btn-ghost btn-sm" data-toggle-target="project-edit-section" id="btn-toggle-edit-project">✎ עריכת פרויקט</button>
            <?php endif; ?>
            <a href="/projects" class="btn btn-ghost btn-sm">← כל הפרויקטים</a>
        </div>
    </div>

    <div class="task-meta-grid">
        <div class="task-meta-item">
            <span class="task-meta-label">לקוח משויך</span>
            <?php if ($project['client_id'] !== null): ?>
                <a class="client-link task-meta-value" href="/clients/<?= e((int) $project['client_id']) ?>?tab=work"><?= e($clientRow['name'] ?? '') ?></a>
            <?php else: ?>
                <span class="level level-warn80">ממתין לשיוך</span>
            <?php endif; ?>
        </div>
        <div class="task-meta-item">
            <span class="task-meta-label">סטטוס פרויקט</span>
            <span class="task-meta-value"><?= e(ProjectService::STATUSES[$project['status']] ?? $project['status']) ?></span>
        </div>
        <div class="task-meta-item">
            <span class="task-meta-label">היקף משימות</span>
            <span class="task-meta-value"><?= e($totalTasks) ?> משימות</span>
        </div>
        <div class="task-meta-item">
            <span class="task-meta-label">התקדמות ביצוע</span>
            <div class="project-progress-wrap">
                <span class="task-meta-value"><?= e($doneTasks) ?> מתוך <?= e($totalTasks) ?> הושלמו (<?= e($pctDone) ?>%)</span>
                <span class="project-progress-track" aria-hidden="true"><span class="project-progress-fill <?= e($widthBucket) ?>"></span></span>
            </div>
        </div>
    </div>

    <?php if (!empty($project['description'])): ?>
    <div class="task-desc-section">
        <h3 class="task-section-heading">תקציר ומטרות הפרויקט</h3>
        <div class="task-desc-box"><?= fmt_rich_text((string) $project['description']) ?></div>
    </div>
    <?php endif; ?>

    <div class="task-desc-section">
        <h3 class="task-section-heading">משימות בפרויקט (<?= e($totalTasks) ?>)</h3>
        <?php if ($tasks === []): ?>
            <p class="task-desc-empty">אין משימות משויכות לפרויקט זה.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table" id="project-tasks-table">
                    <thead>
                        <tr>
                            <th scope="col">משימה</th>
                            <th scope="col">סטטוס</th>
                            <th scope="col">עדיפות</th>
                            <th scope="col">תאריך יעד</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tasks as $t): $sk = TaskService::statusKey((string) $t['status']); ?>
                        <tr>
                            <td class="strong"><a href="/tasks/<?= e((int) $t['id']) ?>"><?= fmt_inline_bidi((string) $t['title']) ?></a></td>
                            <td><span class="tstatus tstatus-<?= e($sk) ?>"><?= e(TaskService::STATUSES[$sk] ?? $t['status']) ?></span></td>
                            <td><?= e(TaskService::PRIORITIES[$t['priority'] ?? 'normal'] ?? ($t['priority'] ?? '')) ?></td>
                            <td><?= e(fmt_date($t['due_at'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($internal && $canUpdate): ?>
<section class="card task-drawer-card<?= $errors === [] ? ' is-hidden' : '' ?>" aria-labelledby="project-edit-title" id="project-edit-section">
    <div class="card-head">
        <h2 id="project-edit-title" class="task-drawer-title">עריכת פרויקט</h2>
        <button type="button" class="btn btn-ghost btn-sm" data-close-target="project-edit-section">✕ סגירה</button>
    </div>
    <form method="post" action="/projects/<?= e($id) ?>" class="task-form-modern mt-sm" id="project-edit-form" novalidate>
        <?= Csrf::field() ?>
        <div class="task-form-grid-2">
            <label class="field">
                <span class="task-form-label">שם הפרויקט *</span>
                <input type="text" name="name" class="task-form-control" value="<?= e($old['name'] ?? '') ?>" required maxlength="200"><?= $err('name') ?>
            </label>
            <label class="field">
                <span class="task-form-label">לקוח * (חובה)</span>
                <select name="client_id" class="task-form-control" required>
                    <option value="">בחרו לקוח…</option>
                    <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                </select><?= $err('client_id') ?>
            </label>
        </div>
        <div class="task-form-grid-2">
            <label class="field">
                <span class="task-form-label">סטטוס</span>
                <select name="status" class="task-form-control"><?php foreach (ProjectService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($old['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            </label>
            <div class="field">
                <span class="task-form-label">נראות בפורטל לקוח</span>
                <div class="task-visibility-box">
                    <input type="checkbox" name="client_visible" id="proj-client-visible" value="1" <?= ($old['client_visible'] ?? '') === '1' ? 'checked' : '' ?>>
                    <label for="proj-client-visible">גלוי ללקוח בפורטל</label>
                </div>
            </div>
        </div>
        <label class="field">
            <span class="task-form-label">תיאור הפרויקט</span>
            <textarea name="description" class="task-form-control" rows="4" maxlength="5000"><?= e($old['description'] ?? '') ?></textarea>
        </label>
        <div class="task-submit-row">
            <button type="submit" class="btn btn-primary btn-lg-submit">שמירת שינויים</button>
        </div>
    </form>
    <?php if ($canArchive): ?>
    <div class="task-archive-bar">
        <form method="post" action="/projects/<?= e($id) ?>/archive" class="inline-form"><?= Csrf::field() ?>
            <input type="hidden" name="archive" value="<?= $project['archived_at'] === null ? '1' : '0' ?>"><input type="hidden" name="_return" value="/projects/<?= e($id) ?>">
            <button type="submit" class="btn btn-ghost btn-sm btn-danger-outline"><?= $project['archived_at'] === null ? 'העברה לארכיון' : 'שחזור מהארכיון' ?></button></form>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
