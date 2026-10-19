<?php
use HubOS\Core\Csrf;

/**
 * Needs-linking queue. Suggestions are only shown; nothing is linked until a button is clicked.
 *
 * @var array{tasks:list<array<string,mixed>>, projects:list<array<string,mixed>>} $queue
 * @var list<array<string, mixed>> $clients
 */
$row = static function (string $type, array $item): string {
    $id = (int) $item['id'];
    ob_start(); ?>
    <tr class="link-row" id="<?= e($type) ?>-link-<?= e($id) ?>">
        <td class="muted">#<?= e($id) ?></td>
        <td class="strong"><?= e($type === 'task' ? $item['title'] : $item['name']) ?><?php if (!empty($item['project_name'])): ?> <span class="muted small">· <?= e($item['project_name']) ?></span><?php endif; ?></td>
        <td>
            <?php if ($item['suggestions'] === []): ?><span class="muted small">אין הצעה</span><?php endif; ?>
            <?php foreach ($item['suggestions'] as $s): ?>
                <form method="post" action="/work/linking/<?= e($type) ?>/<?= e($id) ?>" class="inline-form suggestion">
                    <?= Csrf::field() ?><input type="hidden" name="client_id" value="<?= e($s['client_id']) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm suggestion-btn" data-client="<?= e($s['client_id']) ?>" title="<?= e($s['reason']) ?>">הצעה: <?= e($s['name']) ?> ✓</button>
                    <small class="muted"><?= e($s['reason']) ?></small>
                </form>
            <?php endforeach; ?>
        </td>
        <td>
            <form method="post" action="/work/linking/<?= e($type) ?>/<?= e($id) ?>" class="inline-form">
                <?= Csrf::field() ?>
                <input type="text" name="client_id" list="client-options" required inputmode="numeric" size="10" placeholder="מס' לקוח…" aria-label="בחירת לקוח (מספר או חיפוש שם)">
                <button type="submit" class="btn btn-primary btn-sm">שיוך</button>
            </form>
        </td>
    </tr>
    <?php return (string) ob_get_clean();
};
?>
<datalist id="client-options"><?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>"><?= e($c['name'] . (!empty($c['domain']) ? ' · ' . $c['domain'] : '')) ?></option><?php endforeach; ?></datalist>
<section class="card">
    <p class="muted">משימות ופרויקטים שיובאו בלי לקוח. ההצעות מבוססות על דומיין/שם לקוח בכותרת — <strong>הצעה בלבד</strong>: שום דבר לא משויך עד שלוחצים. כל שיוך נרשם ביומן האירועים.</p>
</section>
<section class="card" aria-labelledby="link-tasks-title">
    <div class="card-head"><h2 id="link-tasks-title">משימות ללא לקוח</h2><span class="badge" id="link-tasks-count"><?= e(count($queue['tasks'])) ?></span></div>
    <?php if ($queue['tasks'] === []): ?><p class="empty">כל המשימות משויכות.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="link-tasks"><thead><tr><th scope="col">#</th><th scope="col">משימה</th><th scope="col">הצעת שיוך</th><th scope="col">שיוך ידני</th></tr></thead>
        <tbody><?php foreach ($queue['tasks'] as $t) { echo $row('task', $t); } ?></tbody></table></div>
    <?php endif; ?>
</section>
<section class="card" aria-labelledby="link-projects-title">
    <div class="card-head"><h2 id="link-projects-title">פרויקטים ללא לקוח</h2><span class="badge" id="link-projects-count"><?= e(count($queue['projects'])) ?></span></div>
    <?php if ($queue['projects'] === []): ?><p class="empty">כל הפרויקטים משויכים.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="link-projects"><thead><tr><th scope="col">#</th><th scope="col">פרויקט</th><th scope="col">הצעת שיוך</th><th scope="col">שיוך ידני</th></tr></thead>
        <tbody><?php foreach ($queue['projects'] as $p) { echo $row('project', $p); } ?></tbody></table></div>
    <?php endif; ?>
</section>
