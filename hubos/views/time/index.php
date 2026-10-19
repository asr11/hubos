<?php
use HubOS\Core\Csrf;

/**
 * /time — entries with filters + totals, manual entry, hours banks (80/90% badges, rollover settings).
 *
 * @var array<string, mixed> $result
 * @var list<array<string, mixed>> $banks
 * @var bool $internal
 * @var bool $canTrack
 * @var bool $canManage
 * @var list<array<string, mixed>> $clients
 * @var list<array<string, mixed>> $users
 * @var list<array<string, mixed>> $tasks
 * @var int $userId
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$f = $result['filters'];
$hm = static fn (int $s): string => sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
$levelLabels = ['ok' => 'תקין', 'warn80' => 'מעל 80%', 'warn90' => 'מעל 90%', 'over' => 'נוצל במלואו'];
$err = static fn (string $k): string => isset($errors[$k]) ? '<small class="field-error">' . e($errors[$k]) . '</small>' : '';
?>
<?php if ($canTrack): ?>
<section class="card" aria-labelledby="manual-title">
    <div class="card-head"><h2 id="manual-title">רישום זמן ידני</h2></div>
    <form method="post" action="/time/entries" class="form form-inline" id="time-entry-form" novalidate>
        <?= Csrf::field() ?>
        <label class="field"><span>לקוח *</span>
            <select name="client_id" id="entry-client" data-role="client-select" required <?= isset($errors['client_id']) ? 'aria-invalid="true"' : '' ?>>
                <option value="">בחרו לקוח…</option>
                <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= ($old['client_id'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select><?= $err('client_id') ?>
        </label>
        <label class="field"><span>משימה</span>
            <select name="task_id" id="entry-task" data-role="task-select">
                <option value="">ללא</option>
                <?php foreach ($tasks as $t): ?><option value="<?= e((int) $t['id']) ?>" data-client="<?= e((int) $t['client_id']) ?>" <?= ($old['task_id'] ?? '') === (string) $t['id'] ? 'selected' : '' ?>><?= e($t['title']) ?></option><?php endforeach; ?>
            </select><?= $err('task_id') ?>
        </label>
        <label class="field"><span>תאריך</span>
            <input type="date" name="entry_date" id="entry-date" value="<?= e($old['entry_date'] ?? \HubOS\Modules\Time\TimeEntryService::today()) ?>"><?= $err('entry_date') ?>
        </label>
        <label class="field"><span>משך * (1:30 / 1.5)</span>
            <input type="text" name="duration" id="entry-duration" value="<?= e($old['duration'] ?? '') ?>" required dir="ltr" <?= isset($errors['duration']) ? 'aria-invalid="true"' : '' ?>><?= $err('duration') ?>
        </label>
        <label class="field field-wide"><span>תיאור *</span>
            <input type="text" name="description" id="entry-description" value="<?= e($old['description'] ?? '') ?>" required maxlength="500" <?= isset($errors['description']) ? 'aria-invalid="true"' : '' ?>><?= $err('description') ?>
        </label>
        <button type="submit" class="btn btn-primary" id="entry-submit">שמירה</button>
    </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="banks-title">
    <div class="card-head"><h2 id="banks-title">בנקי שעות פעילים</h2><span class="badge"><?= e(count($banks)) ?></span></div>
    <?php if ($banks === []): ?><p class="empty">אין בנקי שעות פעילים.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="time-banks-table">
        <thead><tr><th scope="col">לקוח</th><th scope="col">בנק</th><th scope="col">תקופה</th><th scope="col">מכסה</th><th scope="col">נוצל</th><th scope="col">יתרה</th><th scope="col">מצב</th><?php if ($canManage): ?><th scope="col">הגדרות</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($banks as $b): ?>
            <tr id="bank-<?= e((int) $b['id']) ?>">
                <td><?php if ($internal): ?><a class="client-link" href="/clients/<?= e((int) $b['client_id']) ?>?tab=time"><?= e($b['client_name'] ?? '') ?></a><?php endif; ?></td>
                <td class="strong"><?= e($b['name']) ?></td>
                <td><?= $b['period'] === 'monthly' ? 'חודשי' . ((int) $b['rollover'] === 1 ? ' + גלגול' : '') : 'חד-פעמי' ?></td>
                <td><?= e(fmt_hours($b['allowance_hours'])) ?></td>
                <td><?= e(fmt_hours($b['used_hours'])) ?> <span class="muted">(<?= e($b['pct']) ?>%)</span></td>
                <td class="bank-remaining"><?= e(fmt_hours($b['remaining_hours'])) ?></td>
                <td><span class="level level-<?= e($b['level']) ?>"><?= e($levelLabels[$b['level']]) ?></span></td>
                <?php if ($canManage): ?>
                <td>
                    <form method="post" action="/hours-banks/<?= e((int) $b['id']) ?>/settings" class="inline-form">
                        <?= Csrf::field() ?><input type="hidden" name="_return" value="/time">
                        <select name="period" aria-label="תקופה"><option value="one_time" <?= $b['period'] === 'one_time' ? 'selected' : '' ?>>חד-פעמי</option><option value="monthly" <?= $b['period'] === 'monthly' ? 'selected' : '' ?>>חודשי</option></select>
                        <label class="check"><input type="checkbox" name="rollover" value="1" <?= (int) $b['rollover'] === 1 ? 'checked' : '' ?>> גלגול</label>
                        <input type="text" name="rollover_cap_hours" value="<?= e($b['rollover_cap_hours'] ?? '') ?>" placeholder="תקרה" size="4" dir="ltr" aria-label="תקרת גלגול בשעות">
                        <input type="date" name="starts_on" value="<?= e($b['starts_on'] ?? '') ?>" aria-label="תחילת הבנק">
                        <button type="submit" class="btn btn-ghost btn-sm">שמירה</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">התראות: 80% ו-90% ניצול. בנק חודשי עם גלגול: שעות שלא נוצלו עוברות לחודש הבא (עד התקרה, אם הוגדרה).</p>
    <?php endif; ?>
</section>

<section class="card" aria-labelledby="entries-title">
    <div class="card-head">
        <h2 id="entries-title">רישומי זמן</h2>
        <span class="badge" id="time-total">סה"כ <?= e($hm($result['total_seconds'])) ?> שעות · <?= e($result['count']) ?> רישומים</span>
    </div>
    <?php if ($internal): ?>
    <form method="get" action="/time" class="form form-inline search-form" id="time-filter-form">
        <label class="field"><span>משתמש</span>
            <select name="user_id"><option value="">כולם</option>
                <?php foreach ($users as $u): ?><option value="<?= e((int) $u['id']) ?>" <?= $f['user_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span>לקוח</span>
            <select name="client_id"><option value="">כולם</option>
                <?php foreach ($clients as $c): ?><option value="<?= e((int) $c['id']) ?>" <?= $f['client_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="field"><span>מתאריך</span><input type="date" name="from" value="<?= e($f['from'] ?? '') ?>"></label>
        <label class="field"><span>עד תאריך</span><input type="date" name="to" value="<?= e($f['to'] ?? '') ?>"></label>
        <button type="submit" class="btn btn-ghost">סינון</button>
    </form>
    <?php endif; ?>

    <?php if ($result['by_client'] !== [] || $result['by_user'] !== []): ?>
    <div class="grid-2 totals">
        <div><h3>לפי לקוח</h3><ul class="list" id="totals-by-client">
            <?php foreach (array_slice($result['by_client'], 0, 15, true) as $name => $s): ?><li><?= e($name) ?> <span class="muted">· <?= e($hm($s)) ?></span></li><?php endforeach; ?>
        </ul></div>
        <?php if ($result['by_user'] !== []): ?>
        <div><h3>לפי משתמש</h3><ul class="list" id="totals-by-user">
            <?php foreach ($result['by_user'] as $name => $s): ?><li><?= e($name) ?> <span class="muted">· <?= e($hm($s)) ?></span></li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($internal): ?>
        <?php if ($result['rows'] === []): ?><p class="empty">אין רישומי זמן בסינון זה.</p><?php else: ?>
        <div class="table-wrap"><table class="table" id="time-entries-table">
            <thead><tr><th scope="col">תאריך</th><th scope="col">לקוח</th><th scope="col">משימה</th><th scope="col">תיאור</th><th scope="col">משך</th><th scope="col">משתמש</th><th scope="col">מקור</th><th scope="col"></th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $r): ?>
                <tr class="time-entry" id="entry-<?= e((int) $r['id']) ?>">
                    <td><?= e(fmt_date($r['day'])) ?></td>
                    <td><a class="client-link" href="/clients/<?= e((int) $r['client_id']) ?>?tab=time"><?= e($r['client_name'] ?? '—') ?></a></td>
                    <td><?php if ($r['task_id'] !== null): ?><a href="/tasks/<?= e((int) $r['task_id']) ?>"><?= e($r['task_title'] ?? '') ?></a><?php endif; ?></td>
                    <td><?= e($r['description'] ?? '') ?></td>
                    <td dir="ltr" class="ltr-cell"><?= e($hm((int) $r['duration_seconds'])) ?></td>
                    <td><?= e($r['user_name'] ?? ($r['legacy_user_ref'] !== null ? 'ייבוא' : '')) ?></td>
                    <td class="muted"><?= e($r['source'] ?? '') ?></td>
                    <td><?php if ($canManage || (int) ($r['user_id'] ?? 0) === $userId): ?><a href="/time/entries/<?= e((int) $r['id']) ?>" class="btn btn-ghost btn-sm">עריכה</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    <?php else: ?>
        <p class="muted" id="viewer-time-note">מוצגים סיכומי שעות בלבד.</p>
    <?php endif; ?>
</section>
