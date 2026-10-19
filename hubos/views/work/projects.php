<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Work\ProjectService;

/**
 * @var list<array<string, mixed>> $projects
 * @var array<string, string|null> $filters
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var bool $internal
 * @var bool $canCreate
 * @var bool $canArchive
 * @var list<array<string, mixed>> $clients
 */
$archivedView = ($filters['archived'] ?? '') === '1';
$err = static fn (string $k): string => isset($errors[$k]) ? '<small class="field-error">' . e($errors[$k]) . '</small>' : '';
?>
<?php if ($canCreate): ?>
<section class="card" aria-labelledby="new-project-title" id="new">
    <div class="card-head"><h2 id="new-project-title">פרויקט חדש</h2><span class="muted small">שיוך ללקוח הוא חובה</span></div>
    <form method="post" action="/projects" class="form form-inline" id="project-create-form" novalidate>
        <?= Csrf::field() ?>
        <label class="field"><span>שם *</span><input type="text" name="name" value="<?= e($old['name'] ?? '') ?>" required maxlength="200"><?= $err('name') ?></label>
        <label class="field"><span>לקוח * (חובה)</span>
            <select name="client_id" required <?= isset($errors['client_id']) ? 'aria-invalid="true"' : '' ?>>
                <option value="">בחרו לקוח…</option>
                <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select><?= $err('client_id') ?>
        </label>
        <label class="field"><span>סטטוס</span><select name="status"><?php foreach (ProjectService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($old['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field check-field"><span>נראות</span><span class="check"><input type="checkbox" name="client_visible" value="1" <?= ($old['client_visible'] ?? '') === '1' ? 'checked' : '' ?>> גלוי ללקוח</span></label>
        <label class="field field-wide"><span>תיאור</span><textarea name="description" rows="2" maxlength="5000"><?= e($old['description'] ?? '') ?></textarea></label>
        <button type="submit" class="btn btn-primary">יצירת פרויקט</button>
    </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="projects-title">
    <div class="card-head"><h2 id="projects-title"><?= $archivedView ? 'פרויקטים בארכיון' : 'פרויקטים' ?></h2><span class="badge" id="projects-count"><?= e(count($projects)) ?></span></div>
    <?php if ($internal): ?>
    <form method="get" action="/projects" class="form form-inline search-form">
        <label class="field"><span>סטטוס</span><select name="status"><option value="">הכל</option><?php foreach (ProjectService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($filters['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>לקוח</span><select name="client_id"><option value="">כולם</option><?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($filters['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>תצוגה</span><select name="archived"><option value="">פעילים</option><option value="1" <?= $archivedView ? 'selected' : '' ?>>ארכיון</option></select></label>
        <button type="submit" class="btn btn-ghost">סינון</button>
    </form>
    <?php endif; ?>
    <?php if ($projects === []): ?><p class="empty">אין פרויקטים.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="projects-list">
        <thead><tr><th scope="col">פרויקט</th><th scope="col">לקוח</th><th scope="col">סטטוס</th><th scope="col">משימות</th><?php if ($internal): ?><th scope="col">נראות</th><th scope="col"></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($projects as $p): ?>
            <tr id="project-<?= e((int) $p['id']) ?>">
                <td class="strong"><a href="/projects/<?= e((int) $p['id']) ?>"><?= e($p['name']) ?></a></td>
                <td><?php if ($p['client_id'] !== null): ?><a class="client-link" href="/clients/<?= e((int) $p['client_id']) ?>?tab=work"><?= e($p['client_name'] ?? '') ?></a><?php else: ?><span class="level level-warn80">ממתין לשיוך</span><?php endif; ?></td>
                <td><?= e(ProjectService::STATUSES[$p['status']] ?? $p['status']) ?></td>
                <td><?= e((int) $p['task_count']) ?></td>
                <?php if ($internal): ?>
                <td><?= (int) $p['client_visible'] === 1 ? '<span class="visible-flag">גלוי ללקוח</span>' : '<span class="muted">פנימי</span>' ?></td>
                <td><?php if ($canArchive): ?><form method="post" action="/projects/<?= e((int) $p['id']) ?>/archive" class="inline-form"><?= Csrf::field() ?>
                    <input type="hidden" name="archive" value="<?= $archivedView ? '0' : '1' ?>"><input type="hidden" name="_return" value="/projects">
                    <button type="submit" class="btn btn-ghost btn-sm"><?= $archivedView ? 'שחזור' : 'ארכיון' ?></button></form><?php endif; ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
