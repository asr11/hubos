<?php
use HubOS\Core\Csrf;
use HubOS\Core\Str;
use HubOS\Modules\Work\ProjectService;
use HubOS\Modules\Work\TaskService;

/**
 * Needs-linking queue. Suggestions are only shown; nothing is linked until a button is clicked.
 *
 * @var array{tasks:list<array<string,mixed>>, projects:list<array<string,mixed>>} $queue
 * @var list<array<string, mixed>> $clients
 * @var array<string, mixed>|null $hubClient
 * @var bool|null $canArchive
 */
$hubId = is_array($hubClient ?? null) ? (int) $hubClient['id'] : null;
$allowArchive = !empty($canArchive);

$row = static function (string $type, array $item) use ($hubId, $allowArchive): string {
    $id = (int) $item['id'];
    $inputId = 'link-client-' . $type . '-' . $id;
    $rawTitle = (string) ($type === 'task' ? $item['title'] : $item['name']);
    $desc = trim((string) ($item['description'] ?? ''));
    $snippet = $desc !== '' ? Str::limit(preg_replace('/\s+/u', ' ', $desc) ?? $desc, 110) : '';
    $suggestedIds = array_map(static fn (array $s): int => (int) $s['client_id'], $item['suggestions']);
    $showHubBtn = $hubId !== null && !in_array($hubId, $suggestedIds, true);
    $archiveUrl = ($type === 'task' ? '/tasks/' : '/projects/') . $id . '/archive';

    if ($type === 'task') {
        $sk = TaskService::statusKey((string) ($item['status'] ?? 'todo'));
        $stPill = '<span class="tstatus tstatus-' . e($sk) . '">' . e(TaskService::STATUSES[$sk] ?? (string) $item['status']) . '</span>';
    } else {
        $pk = (string) ($item['status'] ?? 'active');
        $stPill = '<span class="pstatus pstatus-' . e($pk) . '">' . e(ProjectService::STATUSES[$pk] ?? $pk) . '</span>';
    }

    ob_start(); ?>
    <tr class="link-row" id="<?= e($type) ?>-link-<?= e($id) ?>" data-link-kind="<?= e($type) ?>">
        <td class="muted link-id-cell">
            <span class="strong" dir="ltr">#<?= e($id) ?></span>
            <div><?= $stPill ?></div>
        </td>
        <td>
            <div class="strong"><a href="/<?= $type === 'task' ? 'tasks' : 'projects' ?>/<?= e($id) ?>"><?= fmt_inline_bidi($rawTitle) ?></a><?php if (!empty($item['project_name'])): ?> <span class="muted small">· <?= fmt_inline_bidi((string) $item['project_name']) ?></span><?php endif; ?></div>
            <?php if ($snippet !== ''): ?><p class="link-desc-snippet muted small"><?= fmt_inline_bidi($snippet) ?></p><?php endif; ?>
        </td>
        <td class="link-quick-cell">
            <div class="link-quick-actions">
                <?php foreach ($item['suggestions'] as $s): ?>
                    <form method="post" action="/work/linking/<?= e($type) ?>/<?= e($id) ?>" class="inline-form suggestion" data-ajax-link="1">
                        <?= Csrf::field() ?><input type="hidden" name="client_id" value="<?= e($s['client_id']) ?>">
                        <button type="submit" class="btn btn-ghost btn-sm suggestion-btn" data-client="<?= e($s['client_id']) ?>" title="<?= e($s['reason']) ?>">הצעה: <?= e($s['name']) ?> ✓</button>
                    </form>
                <?php endforeach; ?>
                <?php if ($showHubBtn): ?>
                    <form method="post" action="/work/linking/<?= e($type) ?>/<?= e($id) ?>" class="inline-form" data-ajax-link="1">
                        <?= Csrf::field() ?><input type="hidden" name="client_id" value="<?= e($hubId) ?>">
                        <button type="submit" class="btn btn-ghost btn-sm btn-hub-internal" title="שיוך כפריט פנימי של האב מערכות מתקדמות">🏢 פנימי (HUB)</button>
                    </form>
                <?php endif; ?>
                <?php if ($item['suggestions'] === [] && !$showHubBtn): ?>
                    <span class="muted small">אין הצעה אוטומטית</span>
                <?php endif; ?>
            </div>
        </td>
        <td class="link-manual-cell">
            <div class="link-manual-wrap">
                <form method="post" action="/work/linking/<?= e($type) ?>/<?= e($id) ?>" class="inline-form link-manual-form" data-ajax-link="1">
                    <?= Csrf::field() ?>
                    <label for="<?= e($inputId) ?>" class="visually-hidden">בחירת לקוח עבור <?= $type === 'task' ? 'משימה' : 'פרויקט' ?> #<?= e($id) ?></label>
                    <input type="text" id="<?= e($inputId) ?>" name="client_id" list="client-options" required placeholder="חיפוש שם לקוח או #מספר…" autocomplete="off" class="link-client-input">
                    <button type="submit" class="btn btn-primary btn-sm">שיוך</button>
                </form>
                <?php if ($allowArchive): ?>
                <form method="post" action="<?= e($archiveUrl) ?>" class="inline-form" data-ajax-link="1">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="archive" value="1">
                    <input type="hidden" name="_return" value="/work/linking">
                    <button type="submit" class="btn btn-ghost btn-sm" title="העברה לארכיון">ארכיון</button>
                </form>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php return (string) ob_get_clean();
};
?>
<datalist id="client-options"><?php foreach ($clients as $c): ?><option value="#<?= e((int) $c['id']) ?> — <?= e($c['name']) ?>"><?= e(!empty($c['domain']) ? $c['domain'] : '') ?></option><?php endforeach; ?></datalist>
<section class="card">
    <p class="muted m-0">משימות ופרויקטים שיובאו ללא לקוח. ההצעות מבוססות על דומיין, שם לקוח ותיאור הפריט — <strong>הצעה בלבד</strong>: שום דבר לא משויך אוטומטית ללא לחיצה מפורשת, וכל פעולה נרשמת ביומן האירועים.</p>
</section>
<section class="card" aria-labelledby="link-tasks-title">
    <div class="card-head"><h2 id="link-tasks-title">משימות ללא לקוח</h2><span class="badge" id="link-tasks-count"><?= e(count($queue['tasks'])) ?></span></div>
    <?php if ($queue['tasks'] === []): ?><p class="empty">כל המשימות משויכות ✓</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="link-tasks"><thead><tr><th scope="col">#</th><th scope="col">משימה ותקציר</th><th scope="col">שיוך מהיר בלחיצה</th><th scope="col">חיפוש לקוח / ארכיון</th></tr></thead>
        <tbody><?php foreach ($queue['tasks'] as $t) { echo $row('task', $t); } ?></tbody></table></div>
    <?php endif; ?>
</section>
<section class="card" aria-labelledby="link-projects-title">
    <div class="card-head"><h2 id="link-projects-title">פרויקטים ללא לקוח</h2><span class="badge" id="link-projects-count"><?= e(count($queue['projects'])) ?></span></div>
    <?php if ($queue['projects'] === []): ?><p class="empty">כל הפרויקטים משויכים ✓</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="link-projects"><thead><tr><th scope="col">#</th><th scope="col">פרויקט ותקציר</th><th scope="col">שיוך מהיר בלחיצה</th><th scope="col">חיפוש לקוח / ארכיון</th></tr></thead>
        <tbody><?php foreach ($queue['projects'] as $p) { echo $row('project', $p); } ?></tbody></table></div>
    <?php endif; ?>
</section>
