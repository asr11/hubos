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
<div class="task-view-container">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
        <a href="/tasks" class="btn btn-ghost btn-sm" id="back-to-tasks">← חזרה לכל המשימות</a>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <?php if ($task['archived_at'] !== null): ?>
                <span class="badge" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;padding:0.35rem 0.75rem;font-size:0.85rem;">בארכיון</span>
            <?php endif; ?>
            <?php if ($internal && $client !== null && $ctx->can('time.track')): ?>
                <form method="post" action="/timer/start" style="margin:0;"><?= Csrf::field() ?>
                    <input type="hidden" name="task_id" value="<?= e($id) ?>"><input type="hidden" name="_return" value="/tasks/<?= e($id) ?>">
                    <button type="submit" class="btn btn-primary" id="task-timer-start" style="display:flex;align-items:center;gap:0.4rem;">
                        <span>▶</span> הפעלת טיימר על המשימה
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($internal && $canUpdate): ?>
                <button type="button" class="btn btn-ghost" onclick="var el = document.getElementById('task-edit-section'); el.style.display = (el.style.display === 'none' ? 'block' : 'none'); if (el.style.display !== 'none') el.scrollIntoView({behavior:'smooth'});" id="btn-toggle-edit">
                    ✏️ עריכת משימה
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Task Overview Card -->
    <section class="card" aria-labelledby="task-title" style="padding:1.75rem;margin-bottom:1.5rem;">
        <div style="border-bottom:1px solid #e2e8f0;padding-bottom:1.25rem;margin-bottom:1.5rem;">
            <div style="display:flex;align-items:baseline;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                <span style="font-size:0.9rem;font-weight:700;color:var(--primary);background:#fff7ed;padding:0.25rem 0.65rem;border-radius:6px;border:1px solid #fdba74;">#<?= e($id) ?></span>
                <h1 id="task-title" style="font-size:1.45rem;font-weight:800;color:#0f172a;margin:0;"><?= e($task['title']) ?></h1>
            </div>
            <?php if ($task['recurring_period'] !== null): ?>
                <div style="font-size:0.85rem;color:#64748b;margin-top:0.25rem;">🔄 משימה חודשית חוזרת (<?= e($task['recurring_period']) ?>)</div>
            <?php endif; ?>
        </div>

        <!-- Spacious Attributes Grid -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:1.25rem;background:#f8fafc;padding:1.25rem;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:1.5rem;">
            <div>
                <div style="font-size:0.8rem;color:#64748b;font-weight:700;margin-bottom:0.35rem;">🏢 לקוח</div>
                <?php if ($client !== null): ?>
                    <a class="client-link" href="/clients/<?= e((int) $client['id']) ?>?tab=work" id="task-client" style="font-size:1.05rem;font-weight:700;color:#0f172a;">
                        <?= e($client['name']) ?>
                    </a>
                <?php else: ?>
                    <span class="level level-warn80" style="font-size:0.85rem;">⚠️ ממתין לשיוך ללקוח</span>
                <?php endif; ?>
            </div>

            <div>
                <div style="font-size:0.8rem;color:#64748b;font-weight:700;margin-bottom:0.35rem;">📊 סטטוס</div>
                <?php 
                    $statusBg = match($sk) {
                        'done' => 'background:#dcfce7;color:#166534;border:1px solid #86efac;',
                        'doing' => 'background:#ffedd5;color:#9a3412;border:1px solid #fdba74;',
                        default => 'background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;'
                    };
                ?>
                <span style="display:inline-block;padding:0.25rem 0.75rem;border-radius:999px;font-size:0.88rem;font-weight:700;<?= $statusBg ?>">
                    <?= e(TaskService::STATUSES[$sk] ?? $task['status']) ?>
                </span>
            </div>

            <div>
                <div style="font-size:0.8rem;color:#64748b;font-weight:700;margin-bottom:0.35rem;">⚡ עדיפות</div>
                <span style="font-size:0.95rem;font-weight:600;color:#334155;">
                    <?= e(TaskService::PRIORITIES[$task['priority']] ?? $task['priority']) ?>
                </span>
            </div>

            <div>
                <div style="font-size:0.8rem;color:#64748b;font-weight:700;margin-bottom:0.35rem;">📅 תאריך יעד</div>
                <span style="font-size:0.95rem;font-weight:600;color:#334155;">
                    <?= !empty($task['due_at']) ? e(fmt_date($task['due_at'])) : '<span style="color:#94a3b8;">ללא תאריך יעד</span>' ?>
                </span>
            </div>

            <div>
                <div style="font-size:0.8rem;color:#64748b;font-weight:700;margin-bottom:0.35rem;">👤 אחראי</div>
                <span style="font-size:0.95rem;font-weight:600;color:#334155;">
                    <?= !empty($task['assignee_name']) ? e($task['assignee_name']) : (!empty($task['assignee']) ? e($task['assignee']) : '<span style="color:#94a3b8;">לא הוקצה</span>') ?>
                </span>
            </div>

            <div>
                <div style="font-size:0.8rem;color:#64748b;font-weight:700;margin-bottom:0.35rem;">👁️ נראות בפורטל</div>
                <span style="font-size:0.95rem;font-weight:600;color:#334155;">
                    <?= !empty($task['client_visible']) ? '<span style="color:#166534;">✓ גלוי ללקוח</span>' : '<span style="color:#64748b;">🔒 פנימי בלבד</span>' ?>
                </span>
            </div>
        </div>

        <!-- Description Box -->
        <div>
            <div style="font-size:0.88rem;color:#475569;font-weight:700;margin-bottom:0.6rem;">📝 תיאור ופרטי המשימה:</div>
            <?php if (!empty($task['description'])): ?>
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:1.25rem 1.5rem;font-size:1rem;line-height:1.75;color:#1e293b;white-space:pre-wrap;box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                    <?= e($task['description']) ?>
                </div>
            <?php else: ?>
                <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:1.25rem;color:#94a3b8;font-size:0.95rem;text-align:center;">
                    אין תיאור למשימה זו.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Collapsible Edit Section -->
    <?php if ($internal && $canUpdate): ?>
    <section class="card" aria-labelledby="task-edit-title" id="task-edit-section" style="<?= $errors === [] ? 'display:none;' : '' ?>padding:1.75rem;border-top:3px solid var(--primary);">
        <div class="card-head">
            <h2 id="task-edit-title" style="font-size:1.25rem;font-weight:800;color:#0f172a;">עריכת פרטי משימה</h2>
            <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('task-edit-section').style.display='none';">✕ סגירת עריכה</button>
        </div>
        <p class="muted small" style="margin-bottom:1.25rem;">שיוך ללקוח הוא חובה · השינויים נרשמים באופן מאובטח ביומן האירועים.</p>
        <?php if ($errors !== []): ?><div class="alert alert-danger" role="alert" id="task-errors"><?= e(implode(' · ', $errors)) ?></div><?php endif; ?>
        <?php $formAction = '/tasks/' . $id; $formId = 'task-edit-form'; $submitLabel = 'שמירת שינויים'; $returnTo = null;
        include __DIR__ . '/_task_form.php'; ?>
        <?php if ($canArchive): ?>
        <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;">
            <form method="post" action="/tasks/<?= e($id) ?>/archive" style="margin:0;"><?= Csrf::field() ?>
                <input type="hidden" name="archive" value="<?= $task['archived_at'] === null ? '1' : '0' ?>"><input type="hidden" name="_return" value="/tasks/<?= e($id) ?>">
                <button type="submit" class="btn btn-ghost btn-sm" id="task-archive" style="color:#ef4444;border-color:#fecaca;">
                    <?= $task['archived_at'] === null ? '🗑️ העברה לארכיון' : '♻️ שחזור מהארכיון' ?>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
