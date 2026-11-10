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
$completedDate = ($sk === 'done' && empty($task['due_at']) && !empty($task['updated_at']))
    ? fmt_date(substr((string) $task['updated_at'], 0, 10))
    : '';
?>
<div class="task-view-container">
    <div class="task-topbar">
        <a href="/tasks" class="btn btn-ghost btn-sm" id="back-to-tasks">← חזרה לכל המשימות</a>
        <div class="page-toolbar-actions">
            <?php if ($task['archived_at'] !== null): ?>
                <span class="badge badge-archived">בארכיון</span>
            <?php endif; ?>
            <?php if ($internal && $client !== null && $ctx->can('time.track')): ?>
                <form method="post" action="/timer/start" class="m-0"><?= Csrf::field() ?>
                    <input type="hidden" name="task_id" value="<?= e($id) ?>"><input type="hidden" name="_return" value="/tasks/<?= e($id) ?>">
                    <button type="submit" class="btn btn-primary" id="task-timer-start">▶ הפעלת טיימר על המשימה</button>
                </form>
            <?php endif; ?>
            <?php if ($internal && $canUpdate): ?>
                <button type="button" class="btn btn-ghost" data-toggle-target="task-edit-section" id="btn-toggle-edit">✏️ עריכת משימה</button>
            <?php endif; ?>
        </div>
    </div>

    <section class="card task-overview-card" aria-labelledby="task-title">
        <div class="task-header-block">
            <div class="task-header-eyebrow">
                <span class="task-id-pill">משימה #<?= e($id) ?></span>
                <?php if ($task['recurring_period'] !== null): ?>
                    <span class="task-recurring-tag">🔄 תחזוקה חודשית (<bdi dir="ltr"><?= e($task['recurring_period']) ?></bdi>)</span>
                <?php endif; ?>
            </div>
            <h1 id="task-title" class="task-main-title"><?= fmt_inline_bidi((string) $task['title']) ?></h1>
        </div>

        <div class="task-meta-grid">
            <div class="task-meta-item">
                <span class="task-meta-label">תיק לקוח</span>
                <div class="task-meta-value">
                    <?php if ($client !== null): ?>
                        <a class="client-link" href="/clients/<?= e((int) $client['id']) ?><?= $internal ? '?tab=work' : '' ?>" id="task-client">
                            <?= e($client['name']) ?>
                        </a>
                    <?php else: ?>
                        <span class="level level-warn80">ממתין לשיוך ללקוח</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="task-meta-item">
                <span class="task-meta-label">סטטוס ביצוע</span>
                <div class="task-meta-value">
                    <span class="tstatus-lg tstatus-<?= e($sk) ?>">
                        <?= $sk === 'done' ? '✓ ' : '' ?><?= e(TaskService::STATUSES[$sk] ?? $task['status']) ?>
                    </span>
                </div>
            </div>

            <div class="task-meta-item">
                <span class="task-meta-label">עדיפות</span>
                <div class="task-meta-value">
                    <span class="tprio tprio-<?= e((string) $task['priority']) ?>">
                        <?= e(TaskService::PRIORITIES[$task['priority']] ?? $task['priority']) ?>
                    </span>
                </div>
            </div>

            <div class="task-meta-item">
                <span class="task-meta-label"><?= $completedDate !== '' ? 'תאריך השלמה' : 'תאריך יעד' ?></span>
                <div class="task-meta-value">
                    <?php if (!empty($task['due_at'])): ?>
                        <bdi dir="ltr"><?= e(fmt_date($task['due_at'])) ?></bdi>
                    <?php elseif ($completedDate !== ''): ?>
                        <bdi dir="ltr"><?= e($completedDate) ?></bdi>
                    <?php else: ?>
                        <span class="task-meta-muted">לפי צורך שוטף</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($internal): ?>
            <div class="task-meta-item">
                <span class="task-meta-label">אחראי פנימי</span>
                <div class="task-meta-value">
                    <?php if (!empty($task['assignee_name'])): ?>
                        <?= e($task['assignee_name']) ?>
                    <?php elseif (!empty($task['assignee'])): ?>
                        <?= e($task['assignee']) ?>
                    <?php else: ?>
                        <span class="task-meta-muted">לא הוקצה</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="task-meta-item">
                <span class="task-meta-label">נראות בפורטל לקוח</span>
                <div class="task-meta-value">
                    <?php if (!empty($task['client_visible'])): ?>
                        <span class="visible-flag">✓ גלוי ללקוח</span>
                    <?php else: ?>
                        <span class="task-meta-muted">🔒 פנימי בלבד</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <h2 class="task-desc-heading">פירוט העבודה וסיכום ביצוע</h2>
            <?php if (!empty($task['description'])): ?>
                <div class="task-desc-box" id="task-description">
                    <?= fmt_rich_text((string) $task['description']) ?>
                </div>
            <?php else: ?>
                <div class="task-desc-empty">
                    אין פירוט נוסף למשימה זו.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($internal && $canUpdate): ?>
    <section class="card task-drawer-card<?= $errors === [] ? ' is-hidden' : '' ?>" aria-labelledby="task-edit-title" id="task-edit-section">
        <div class="card-head">
            <h2 id="task-edit-title" class="task-drawer-title">עריכת פרטי משימה</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-close-target="task-edit-section">✕ סגירת עריכה</button>
        </div>
        <p class="muted small mb-md">שיוך ללקוח הוא חובה · השינויים נרשמים באופן מאובטח ביומן האירועים.</p>
        <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert" id="task-errors"><?= e(implode(' · ', $errors)) ?></div><?php endif; ?>
        <?php $formAction = '/tasks/' . $id; $formId = 'task-edit-form'; $submitLabel = 'שמירת שינויים'; $returnTo = null;
        include __DIR__ . '/_task_form.php'; ?>
        <?php if ($canArchive): ?>
        <div class="task-archive-bar">
            <form method="post" action="/tasks/<?= e($id) ?>/archive" class="m-0"><?= Csrf::field() ?>
                <input type="hidden" name="archive" value="<?= $task['archived_at'] === null ? '1' : '0' ?>"><input type="hidden" name="_return" value="/tasks/<?= e($id) ?>">
                <button type="submit" class="btn btn-ghost btn-sm btn-danger-outline" id="task-archive">
                    <?= $task['archived_at'] === null ? 'העברה לארכיון' : 'שחזור מהארכיון' ?>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

