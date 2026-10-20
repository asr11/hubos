<?php
use HubOS\Core\Csrf;
use HubOS\Modules\Clients\Client360Service;

/**
 * Client 360 card. Tabs are plain links (?tab=…) rendered server-side: no JS.
 *
 * @var array<string, mixed> $card
 * @var string $tab
 * @var bool $canEnrich
 * @var array<string, string|null>|null $suggestion
 * @var string|null $registryError
 */
$c = $card['client'];
$m = $card['metrics'];
$id = (int) $c['id'];
$statusLabels = ['active' => 'פעיל', 'inactive' => 'לא פעיל', 'archived' => 'בארכיון'];
$taskStatus = ['todo' => 'לביצוע', 'doing' => 'בתהליך', 'in_progress' => 'בתהליך', 'done' => 'בוצע'];
$priorityLabels = \HubOS\Modules\Work\TaskService::PRIORITIES;
$levelLabels = ['ok' => 'תקין', 'warn80' => 'מעל 80%', 'warn90' => 'מעל 90%', 'over' => 'נוצל במלואו'];
$canTrack = $canTrack ?? false;
$canWork = $canWork ?? false;
$timer = $timer ?? null;
$ownBank = $card['own_bank'] ?? null;
$hm = static fn (int $s): string => sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60));
$projStatus = ['active' => 'פעיל', 'done' => 'הסתיים', 'paused' => 'מושהה'];
$docStatus = ['paid' => 'שולם', 'unpaid' => 'לא שולם', 'pending' => 'ממתין', 'partial' => 'שולם חלקית'];
$interactionKinds = ['note' => 'הערה', 'call' => 'שיחה', 'meeting' => 'פגישה', 'whatsapp' => 'ווטסאפ', 'email' => 'מייל', 'task_note' => 'הערת משימה', 'task_activity' => 'פעילות משימה'];
$assetKinds = ['domain' => 'דומיין', 'hosting' => 'אחסון', 'server' => 'שרת', 'other' => 'אחר'];
$origin = static function (array $row): string {
    if ($row['is_own'] || $row['origin_client_id'] === null) {
        return '<span class="origin origin-own">' . e($row['origin_name']) . '</span>';
    }
    return '<a class="origin origin-family" href="/clients/' . e($row['origin_client_id']) . '">' . e($row['origin_name']) . '</a>';
};
$familyRolled = count($card['family']) > 1;
?>
<section class="card client-head" aria-labelledby="client-name">
    <div class="client-id">
        <p class="eyebrow"><a href="/clients">← כל הלקוחות</a></p>
        <h2 id="client-name"><?= e($c['name']) ?></h2>
        <p class="client-meta">
            <span>ח.פ: <bdi dir="ltr" id="client-tax-id"><?= e($c['tax_id'] ?? '—') ?></bdi></span>
            <span class="status status-<?= e($c['status']) ?>"><?= e($statusLabels[$c['status']] ?? $c['status']) ?></span>
            <?php if (!empty($c['city'])): ?><span><?= e($c['city']) ?></span><?php endif; ?>
            <?php if (!empty($c['domain'])): ?><span dir="ltr"><?= e($c['domain']) ?></span><?php endif; ?>
        </p>
        <?php if ($card['internal'] && ($card['ancestors'] !== [] || $card['children'] !== [])): ?>
            <nav class="family" aria-label="משפחת חברות" id="client-family">
                <?php foreach (array_reverse($card['ancestors']) as $a): ?>
                    <span class="family-label">חברת אם:</span>
                    <a class="family-link family-parent" href="/clients/<?= e((int) $a['id']) ?>"><?= e($a['name']) ?></a>
                <?php endforeach; ?>
                <?php if ($card['children'] !== []): ?>
                    <span class="family-label">חברות בנות:</span>
                    <?php foreach ($card['children'] as $ch): ?>
                        <a class="family-link family-child" href="/clients/<?= e((int) $ch['id']) ?>"><?= e($ch['name']) ?></a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>
            <p class="muted small">הנתונים בכרטיס מצורפים (Roll-up) מכל המשפחה: <?= e(implode(' · ', $card['family'])) ?>. כל שורה מסומנת בלקוח שממנו היא מגיעה.</p>
        <?php endif; ?>
        <?php if (!empty($card['internal']) && is_array($card['provenance'] ?? null)): $prov = $card['provenance']; ?>
            <div class="cluster small" id="client-provenance-banner" style="margin-top:0.5rem;gap:0.75rem;flex-wrap:wrap;padding:0.4rem 0.65rem;background:var(--surface-2, #f8fafc);border-radius:6px;border-right:3px solid var(--ok, #16a34a)">
                <span class="pill pill-ok">✔ מקור מאומת: <bdi dir="ltr"><?= e((string) ($prov['source_label'] ?? 'Cloudflare D1 (hub-os-d1)')) ?></bdi></span>
                <span>שליפה: <bdi dir="ltr" id="client-prov-timestamp"><?= e((string) ($prov['fetched_at_il'] ?? '')) ?></bdi> (<?= e((string) ($prov['relative_age'] ?? '')) ?>)</span>
                <span>SHA-256: <code dir="ltr" id="client-prov-sha256"><?= e((string) ($prov['sha256_short'] ?? '')) ?></code></span>
                <?php if ($canEnrich ?? false): ?>
                <form method="post" action="/settings/sync-d1" style="margin:0;display:inline">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="_return" value="/clients/<?= e($id) ?>">
                    <button type="submit" class="btn btn-sm" style="padding:0.15rem 0.5rem;font-size:0.78rem">סנכרון ואימות עכשיו ↻</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="kpis">
        <?php if (!empty($m['can_finance'])): ?>
        <div class="kpi" id="kpi-balance">
            <span class="kpi-label">יתרת חוב</span>
            <?php if (($m['open_docs_amount'] ?? 0) > 0 || ($m['open_demands_amount'] ?? 0) > 0): ?>
                <span class="kpi-value kpi-alert"><?= e(fmt_money($m['open_docs_amount'] > 0 ? $m['open_docs_amount'] : $m['open_demands_amount'])) ?></span>
                <span class="kpi-hint"><?= e($m['open_docs_count'] ?: $m['open_demands_count']) ?> מסמכים פתוחים לגבייה</span>
            <?php else: ?>
                <span class="kpi-value kpi-ok">₪0</span>
                <span class="kpi-hint">אין חוב פתוח · שולם במלואו</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="kpi" id="kpi-hours-month">
            <span class="kpi-label">שעות החודש</span>
            <span class="kpi-value"><?= e(fmt_hours($m['hours_this_month'])) ?></span>
            <span class="kpi-hint"><?= e($m['month']) ?></span>
        </div>
        <div class="kpi" id="kpi-bank">
            <span class="kpi-label">יתרה בבנק השעות
                <?php if ($ownBank !== null && $ownBank['level'] !== 'ok'): ?><span class="level level-<?= e($ownBank['level']) ?>" id="bank-alert"><?= e($ownBank['pct']) ?>%</span><?php endif; ?></span>
            <span class="kpi-value" id="bank-remaining"><?= $m['bank_remaining'] === null ? '—' : e(fmt_hours($m['bank_remaining'])) ?></span>
            <span class="kpi-hint"><?php if ($ownBank !== null): ?>נוצלו <?= e(fmt_hours($ownBank['used_hours'])) ?> מתוך <?= e(fmt_hours($ownBank['allowance_hours'])) ?><?php elseif ($m['bank_remaining'] === null): ?>אין בנק שעות פעיל<?php else: ?>שעות בבנקים פעילים<?php endif; ?></span>
        </div>
        <?php if (!empty($m['can_finance'])): ?>
        <div class="kpi" id="kpi-income">
            <span class="kpi-label">הכנסה <?= e($m['year']) ?></span>
            <span class="kpi-value"><?= e(fmt_money($m['income_this_year'])) ?></span>
            <span class="kpi-hint">לפי חוק המסמך החשבונאי · ברוטו</span>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($canTrack): ?>
    <div class="client-timer" id="client-timer">
        <?php if ($timer !== null && (int) $timer['client_id'] === $id): ?>
            <span class="timer-dot <?= $timer['running'] ? 'is-running' : 'is-paused' ?>" aria-hidden="true"></span>
            <strong id="client-timer-running">טיימר <?= $timer['running'] ? 'רץ' : 'מושהה' ?> על הלקוח</strong>
            <span class="muted"><?= e($timer['task_title'] ?? '') ?> · <?= e($hm((int) $timer['elapsed_seconds'])) ?> · לעצירה: בסרגל העליון</span>
        <?php elseif ($timer !== null): ?>
            <span class="muted" id="client-timer-other">טיימר פעיל על <a href="/clients/<?= e((int) $timer['client_id']) ?>"><?= e($timer['client_name'] ?? '') ?></a> — יש לעצור אותו לפני הפעלה כאן.</span>
        <?php else: ?>
            <form method="post" action="/timer/start" class="inline-form" id="client-timer-form">
                <?= Csrf::field() ?><input type="hidden" name="client_id" value="<?= e($id) ?>"><input type="hidden" name="_return" value="/clients/<?= e($id) ?>?tab=time">
                <select name="task_id" aria-label="משימה (רשות)"><option value="">ללא משימה</option>
                    <?php foreach ($card['open_tasks'] as $ot): ?><?php if ($ot['is_own']): ?><option value="<?= e((int) $ot['id']) ?>"><?= e($ot['title']) ?></option><?php endif; ?><?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm" id="client-timer-start">▶ הפעלת טיימר ללקוח</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<?php if ($canEnrich): ?>
<section class="card" id="registry" aria-labelledby="registry-title">
    <div class="card-head">
        <h2 id="registry-title">רשם החברות</h2>
        <form method="post" action="/clients/<?= e($id) ?>/enrich">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-ghost btn-sm" id="registry-enrich">העשרה מרשם החברות לפי ח.פ</button>
        </form>
    </div>
    <?php if ($registryError !== null): ?>
        <div class="alert alert-danger" role="alert" id="registry-error"><?= e($registryError) ?></div>
    <?php endif; ?>
    <?php if ($suggestion !== null): ?>
        <form method="post" action="/clients/<?= e($id) ?>/enrich/apply" id="registry-apply-form">
            <?= Csrf::field() ?>
            <p class="muted">הצעות בלבד: שום דבר לא נשמר עד שלוחצים "החלה".</p>
            <div class="table-wrap"><table class="table" id="registry-suggestion">
                <thead><tr><th scope="col">שדה</th><th scope="col">ברשם</th><th scope="col">בכרטיס</th><th scope="col">לעדכן?</th></tr></thead>
                <tbody>
                <tr><td>שם רשמי</td><td><?= e($suggestion['name']) ?> <span class="muted" dir="ltr"><?= e($suggestion['name_en']) ?></span></td><td><?= e($c['name']) ?></td>
                    <td><input type="checkbox" name="fields[]" value="name" aria-label="לעדכן שם"></td></tr>
                <tr><td>כתובת</td><td><?= e($suggestion['address']) ?></td><td><?= e($c['address'] ?? '') ?></td>
                    <td><input type="checkbox" name="fields[]" value="address" aria-label="לעדכן כתובת" <?= empty($c['address']) ? 'checked' : '' ?>></td></tr>
                <tr><td>עיר</td><td><?= e($suggestion['city']) ?> <?= e($suggestion['zip']) ?></td><td><?= e($c['city'] ?? '') ?></td>
                    <td><input type="checkbox" name="fields[]" value="city" aria-label="לעדכן עיר" <?= empty($c['city']) ? 'checked' : '' ?>></td></tr>
                <tr><td>סטטוס חברה</td><td><?= e($suggestion['status']) ?> · <?= e($suggestion['company_type']) ?></td><td colspan="2" class="muted">נשמר כמידע רשם</td></tr>
                <tr><td>תאריך התאגדות</td><td><?= e(fmt_date($suggestion['incorporation_date'])) ?></td><td colspan="2" class="muted">נשמר כמידע רשם</td></tr>
                </tbody>
            </table></div>
            <button type="submit" class="btn btn-primary" id="registry-apply">החלה ושמירה</button>
        </form>
    <?php elseif ($card['registry'] !== null): ?>
        <p class="muted" id="registry-applied">
            נתוני רשם (הוחלו <?= e(fmt_dt($card['registry']['applied_at'] ?? null)) ?>):
            <?= e($card['registry']['name'] ?? '') ?> · <?= e($card['registry']['status'] ?? '') ?> ·
            התאגדות <?= e(fmt_date($card['registry']['incorporation_date'] ?? null)) ?>
        </p>
    <?php endif; ?>
</section>
<?php endif; ?>

<nav class="tabs" aria-label="לשוניות כרטיס לקוח" id="client-tabs">
    <?php foreach (Client360Service::TABS as $key => $label): ?>
        <?php if ($key === 'access' && !$card['internal']) { continue; } ?>
        <?php if (in_array($key, ['finance', 'collection'], true) && empty($card['metrics']['can_finance'])) { continue; } ?>
        <a id="tab-<?= e($key) ?>" href="/clients/<?= e($id) ?>?tab=<?= e($key) ?>" class="tab<?= $tab === $key ? ' is-active' : '' ?>"
            <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?> <span class="badge"><?= e($card['counts'][$key]) ?></span></a>
    <?php endforeach; ?>
</nav>

<section class="card tab-panel" id="panel-<?= e($tab) ?>" aria-labelledby="tab-<?= e($tab) ?>">
<?php if ($tab === 'overview'): ?>
    <div class="grid-2">
        <div>
            <h3>התראות</h3>
            <?php if ($card['alerts'] === []): ?><p class="empty">אין התראות.</p><?php endif; ?>
            <ul class="alerts">
                <?php foreach ($card['alerts'] as $al): ?><li class="alert-<?= e($al['level']) ?>"><?= e($al['text']) ?></li><?php endforeach; ?>
            </ul>
            <h3>משימות פתוחות (<?= e(count($card['open_tasks'])) ?>)</h3>
            <?php if ($card['open_tasks'] === []): ?><p class="empty">אין משימות פתוחות.</p><?php endif; ?>
            <ul class="list">
                <?php foreach (array_slice($card['open_tasks'], 0, 10) as $t): ?>
                    <li><?= e($t['title']) ?> <span class="muted">· <?= e($taskStatus[$t['status']] ?? $t['status']) ?> · <?= $origin($t) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div>
            <h3>אנשי קשר עיקריים</h3>
            <ul class="list">
                <?php foreach (array_slice($card['contacts'], 0, 5) as $p): ?>
                    <li><strong><?= e($p['name']) ?></strong> <span class="muted"><?= e($p['role'] ?? '') ?></span> <?= $origin($p) ?></li>
                <?php endforeach; ?>
                <?php if ($card['contacts'] === []): ?><li class="empty">אין אנשי קשר.</li><?php endif; ?>
            </ul>
            <h3>פעילות אחרונה</h3>
            <ul class="list">
                <?php foreach (array_slice($card['timeline'], 0, 8) as $it): ?>
                    <li><span class="muted"><?= e(fmt_date($it['date'])) ?></span> · <?= e($it['kind']) ?>: <?= e($it['text']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

<?php elseif ($tab === 'contacts'): ?>
    <?php if ($card['contacts'] === []): ?><p class="empty">אין אנשי קשר.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="contacts-table">
        <thead><tr><th scope="col">שם</th><th scope="col">תפקיד</th><th scope="col">טלפון</th><th scope="col">נייד</th><th scope="col">אימייל</th><th scope="col">ראשי</th><th scope="col">הערות</th><th scope="col">לקוח</th></tr></thead>
        <tbody>
        <?php foreach ($card['contacts'] as $p): ?>
            <tr><td class="strong"><?= e($p['name']) ?></td><td><?= e($p['role'] ?? '') ?></td>
                <td dir="ltr" class="ltr-cell"><?= e($p['phone'] ?? '') ?></td><td dir="ltr" class="ltr-cell"><?= e($p['mobile'] ?? '') ?></td>
                <td dir="ltr" class="ltr-cell"><?= e($p['email'] ?? '') ?></td><td><?= (int) $p['is_primary'] === 1 ? '✓' : '' ?></td>
                <td><?= e($p['notes'] ?? '') ?></td><td><?= $origin($p) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>

<?php elseif ($tab === 'interactions'): ?>
    <?php if ($card['interactions'] === []): ?><p class="empty">אין רישומי תקשורת.</p><?php endif; ?>
    <ol class="timeline" id="interactions-list">
        <?php foreach ($card['interactions'] as $i): ?>
            <li class="timeline-item"><span class="dot" aria-hidden="true"></span>
                <div><strong><?= e($interactionKinds[$i['kind']] ?? $i['kind']) ?>: <?= e($i['title'] ?? '') ?></strong> <?= $origin($i) ?>
                    <?php if (!empty($i['summary'])): ?><p class="pre"><?= e($i['summary']) ?></p><?php endif; ?>
                    <time><?= e(fmt_dt($i['occurred_at'] ?? $i['created_at'])) ?></time></div></li>
        <?php endforeach; ?>
    </ol>

<?php elseif ($tab === 'work'): ?>
    <?php if ($canWork): ?><p class="tab-actions"><a class="btn btn-ghost btn-sm" id="work-new-task" href="/tasks?client_id=<?= e($id) ?>#new">+ משימה חדשה ללקוח</a>
        <a class="btn btn-ghost btn-sm" href="/projects?client_id=<?= e($id) ?>#new">+ פרויקט חדש</a></p><?php endif; ?>
    <h3>פרויקטים (<?= e(count($card['projects'])) ?>)</h3>
    <?php if ($card['projects'] === []): ?><p class="empty">אין פרויקטים.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="projects-table">
        <thead><tr><th scope="col">פרויקט</th><th scope="col">סטטוס</th><th scope="col">נוצר</th><th scope="col">לקוח</th></tr></thead>
        <tbody><?php foreach ($card['projects'] as $p): ?>
            <tr><td class="strong"><?php if ($card['internal']): ?><a href="/projects/<?= e((int) $p['id']) ?>"><?= e($p['name']) ?></a><?php else: ?><?= e($p['name']) ?><?php endif; ?></td><td><?= e($projStatus[$p['status']] ?? $p['status']) ?></td><td class="muted"><?= e(fmt_dt($p['created_at'])) ?></td><td><?= $origin($p) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <h3>משימות (<?= e(count($card['tasks'])) ?>)</h3>
    <?php if ($card['tasks'] === []): ?><p class="empty">אין משימות.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="tasks-table">
        <thead><tr><th scope="col">משימה</th><th scope="col">פרויקט</th><th scope="col">סטטוס</th><th scope="col">עדיפות</th><?php if ($card['internal']): ?><th scope="col">נראות</th><?php endif; ?><th scope="col">יעד</th><th scope="col">לקוח</th><?php if ($canTrack): ?><th scope="col"></th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($card['tasks'] as $t): ?>
            <tr class="work-task" id="work-task-<?= e((int) $t['id']) ?>"><td class="strong"><a href="/tasks/<?= e((int) $t['id']) ?>"><?= e($t['title']) ?></a></td><td><?= e($t['project_name'] ?? '') ?></td><td><?= e($taskStatus[$t['status']] ?? $t['status']) ?></td>
                <td><?= e($priorityLabels[$t['priority'] ?? 'normal'] ?? '') ?></td>
                <?php if ($card['internal']): ?><td><?= (int) ($t['client_visible'] ?? 0) === 1 ? '<span class="visible-flag">גלוי ללקוח</span>' : '<span class="muted">פנימי</span>' ?></td><?php endif; ?>
                <td><?= e(fmt_date($t['due_at'] ?? null)) ?></td><td><?= $origin($t) ?></td>
                <?php if ($canTrack): ?><td><?php if ($timer === null && $t['client_id'] !== null): ?><form method="post" action="/timer/start" class="inline-form"><?= Csrf::field() ?>
                    <input type="hidden" name="task_id" value="<?= e((int) $t['id']) ?>"><input type="hidden" name="_return" value="/clients/<?= e($id) ?>?tab=work">
                    <button type="submit" class="btn btn-ghost btn-sm" title="טיימר על המשימה">▶</button></form><?php endif; ?></td><?php endif; ?></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

<?php elseif ($tab === 'time'): ?>
    <h3>בנקי שעות (<?= e(count($card['hours_banks'])) ?>)</h3>
    <?php if ($card['hours_banks'] === []): ?><p class="empty">אין בנק שעות.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="banks-table">
        <thead><tr><th scope="col">בנק</th><th scope="col">סטטוס</th><th scope="col">תקופה</th><th scope="col">מכסה</th><th scope="col">נוצל</th><th scope="col">יתרה</th><th scope="col">מצב</th><th scope="col">תעריף</th><th scope="col">לקוח</th></tr></thead>
        <tbody><?php foreach ($card['hours_banks'] as $b): ?>
            <tr class="<?= $b['low'] ? 'row-warn' : '' ?>" id="card-bank-<?= e((int) $b['id']) ?>"><td class="strong"><?= e($b['name']) ?></td><td><?= e($b['status']) ?></td>
                <td><?= ($b['period'] ?? 'one_time') === 'monthly' ? 'חודשי' . ((int) ($b['rollover'] ?? 0) === 1 ? ' + גלגול' : '') : 'חד-פעמי' ?></td>
                <td><?= e(fmt_hours($b['allowance_hours'])) ?></td>
                <td><?= e(fmt_hours($b['used_hours'])) ?> <span class="muted">(<?= e($b['pct']) ?>%)</span></td><td class="bank-remaining"><?= e(fmt_hours($b['remaining_hours'])) ?></td>
                <td><span class="level level-<?= e($b['level']) ?>"><?= e($levelLabels[$b['level']] ?? '') ?></span></td>
                <td><?= $b['rate'] !== null ? e(fmt_money($b['rate'])) : '' ?></td><td><?= $origin($b) ?></td></tr>
            <?php if (($b['months'] ?? []) !== [] && (int) ($b['rollover'] ?? 0) === 1): ?>
            <tr class="bank-months"><td colspan="9" class="muted small">גלגול: <?php foreach (array_slice($b['months'], -4) as $mo): ?><?= e($mo['month']) ?> — מכסה <?= e(fmt_hours($mo['allowance'])) ?> (מתוכה הועבר <?= e(fmt_hours($mo['carried_in'])) ?>), נוצל <?= e(fmt_hours($mo['used'])) ?> · <?php endforeach; ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?></tbody>
    </table></div>
    <p class="muted small">ניצול = סכום רישומי זמן המקושרים לבנק. התראות ב-80% וב-90%. רישום חדש מנוכה אוטומטית מהבנק הפעיל של הלקוח.</p>
    <?php endif; ?>
    <?php if ($canTrack): ?>
    <h3>רישום זמן ידני</h3>
    <form method="post" action="/time/entries" class="form form-inline" id="card-entry-form">
        <?= Csrf::field() ?><input type="hidden" name="client_id" value="<?= e($id) ?>"><input type="hidden" name="_return" value="/clients/<?= e($id) ?>?tab=time">
        <label class="field"><span>משימה</span><select name="task_id"><option value="">ללא</option>
            <?php foreach ($card['open_tasks'] as $ot): ?><?php if ($ot['is_own']): ?><option value="<?= e((int) $ot['id']) ?>"><?= e($ot['title']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
        <label class="field"><span>תאריך</span><input type="date" name="entry_date" value="<?= e(\HubOS\Modules\Time\TimeEntryService::today()) ?>"></label>
        <label class="field"><span>משך * (1:30)</span><input type="text" name="duration" required dir="ltr"></label>
        <label class="field field-wide"><span>תיאור *</span><input type="text" name="description" required maxlength="500"></label>
        <button type="submit" class="btn btn-primary">שמירה</button>
    </form>
    <?php endif; ?>
    <h3>רישומי זמן (<?= e($card['counts']['time']) ?> · סה"כ <?= e(fmt_hours($m['hours_total'])) ?> שעות · החודש <?= e(fmt_hours($m['hours_this_month'])) ?>)</h3>
    <?php if ($card['time_entries'] === []): ?><p class="empty"><?= $card['internal'] ? 'אין רישומי זמן.' : 'מוצגים סיכומי שעות בלבד.' ?></p><?php else: ?>
    <div class="table-wrap"><table class="table" id="time-table">
        <thead><tr><th scope="col">תאריך</th><th scope="col">תיאור</th><th scope="col">שעות</th><th scope="col">מקור</th><th scope="col">לקוח</th><?php if ($canTrack): ?><th scope="col"></th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($card['time_entries'] as $te): ?>
            <tr class="card-time-entry"><td><?= e(fmt_date($te['entry_date'] ?? $te['started_at'])) ?></td><td><?= e($te['description'] ?? '') ?></td>
                <td><?= e(fmt_hours((int) $te['duration_seconds'] / 3600)) ?></td><td class="muted"><?= e($te['source'] ?? '') ?></td><td><?= $origin($te) ?></td>
                <?php if ($canTrack): ?><td><a class="btn btn-ghost btn-sm" href="/time/entries/<?= e((int) $te['id']) ?>">עריכה</a></td><?php endif; ?></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

<?php elseif ($tab === 'finance'): ?>
    <p class="muted">הכנסה מחושבת לפי חוק המסמך החשבונאי (שדה counts_as_income): אין ספירה כפולה של חשבונית וקבלה. סכומים ברוטו כולל מע"מ.</p>
    <?php if ($card['finance'] === []): ?><p class="empty">אין מסמכים כספיים.</p><?php endif; ?>
    <?php $byYear = []; foreach ($card['finance'] as $d) { $byYear[$d['year']][] = $d; } ?>
    <?php foreach ($byYear as $y => $docs): ?>
        <h3><?= e($y) ?> · <?= e(count($docs)) ?> מסמכים · הכנסה <?= e(fmt_money($m['income_by_year'][$y] ?? 0)) ?></h3>
        <div class="table-wrap"><table class="table finance-table">
            <thead><tr><th scope="col">תאריך</th><th scope="col">סוג</th><th scope="col">מספר</th><th scope="col">סכום</th><th scope="col">סטטוס</th><th scope="col">הכנסה?</th><th scope="col">לקוח</th></tr></thead>
            <tbody><?php foreach ($docs as $d): ?>
                <tr class="finance-doc"><td><?= e(fmt_date($d['doc_date'])) ?></td><td><?= e($d['kind_label']) ?></td><td dir="ltr" class="ltr-cell"><?= e($d['doc_number'] ?? '') ?></td>
                    <td><?= e(fmt_money($d['amount'], 2)) ?></td><td><?= e($docStatus[$d['status']] ?? ($d['status'] ?? '')) ?></td>
                    <td title="<?= e($d['income_reason'] ?? '') ?>"><?= (int) $d['counts_as_income'] === 1 ? '✓' : '—' ?></td><td><?= $origin($d) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endforeach; ?>

<?php elseif ($tab === 'collection'): ?>
    <div class="alert alert-danger" role="note" id="collection-caveat">
        יתרת חוב מדויקת <strong>אינה ניתנת לחישוב אמין</strong> מהנתונים: אין התאמה מלאה בין חשבוניות לקבלות.
        חוק המסמך החשבונאי (מאושר בעלים): <strong>חשבון עסקה (300)</strong> הוא דרישת תשלום ואינו נספר כהכנסה; <strong>חשבונית מס (305)</strong> ו-<strong>חשבונית מס/קבלה (320)</strong> הם הכנסה מוכרת; <strong>קבלה (400)</strong> נספרת רק אם אין חשבונית תואמת.
    </div>
    <h3>דרישות תשלום פתוחות — חשבונות עסקה (300) ללא תשלום תואם (<?= e(count($card['open_demands'] ?? [])) ?> · <?= e(fmt_money($m['open_demands_amount'] ?? 0)) ?>)</h3>
    <?php if (($card['open_demands'] ?? []) === []): ?><p class="empty">אין חשבונות עסקה (300) פתוחים ללא מסמך סגירה תואם.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="open-demands-table">
        <thead><tr><th scope="col">תאריך</th><th scope="col">סוג</th><th scope="col">מספר</th><th scope="col">סכום דרישה</th><th scope="col">סטטוס Morning</th><th scope="col">לקוח</th></tr></thead>
        <tbody><?php foreach ($card['open_demands'] as $od): ?>
            <tr class="open-demand-row"><td><?= e(fmt_date($od['doc_date'])) ?></td><td><?= e($od['kind_label']) ?></td><td dir="ltr" class="ltr-cell"><?= e($od['doc_number'] ?? '') ?></td>
                <td class="strong"><?= e(fmt_money($od['amount'], 2)) ?></td><td><?= e($docStatus[$od['status']] ?? ($od['status'] ?? 'פתוח')) ?></td><td><?= $origin($od) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <h3>חשבוניות מול קבלות לפי שנה</h3>
    <?php if ($card['collection'] === []): ?><p class="empty">אין מסמכים.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="collection-table">
        <thead><tr><th scope="col">שנה</th><th scope="col">חשבון עסקה (300)</th><th scope="col">300 פתוחים</th><th scope="col">חשבונית מס (305)</th><th scope="col">חשבונית מס/קבלה (320)</th><th scope="col">קבלות (400)</th><th scope="col">קבלות כהכנסה</th><th scope="col">הכנסה מוכרת (לפי החוק)</th></tr></thead>
        <tbody><?php foreach ($card['collection'] as $y => $row): ?>
            <tr><td><?= e($y) ?></td>
                <td><?= e($row['proformas'][0] ?? 0) ?> · <?= e(fmt_money($row['proformas'][1] ?? 0)) ?></td>
                <td><?= e($row['proformas_open'][0] ?? 0) ?> · <?= e(fmt_money($row['proformas_open'][1] ?? 0)) ?></td>
                <td><?= e($row['invoices'][0]) ?> · <?= e(fmt_money($row['invoices'][1])) ?></td>
                <td><?= e($row['invoice_receipts'][0]) ?> · <?= e(fmt_money($row['invoice_receipts'][1])) ?></td>
                <td><?= e($row['receipts'][0]) ?> · <?= e(fmt_money($row['receipts'][1])) ?></td>
                <td><?= e($row['receipts_income']) ?> (מתוך <?= e($row['receipts'][0]) ?>)</td>
                <td class="strong"><?= e(fmt_money($row['income'])) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <h3>מסמכים במצב "לא שולם" לפי Morning (<?= e(count($card['open_docs'])) ?>)</h3>
    <?php if ($card['open_docs'] === []): ?><p class="empty">אין.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="open-docs-table">
        <thead><tr><th scope="col">תאריך</th><th scope="col">סוג</th><th scope="col">מספר</th><th scope="col">סכום</th><th scope="col">שולם</th><th scope="col">פתוח לפי המסמך</th><th scope="col">סטטוס</th><th scope="col">לקוח</th></tr></thead>
        <tbody><?php foreach ($card['open_docs'] as $d): ?>
            <tr><td><?= e(fmt_date($d['doc_date'])) ?></td><td><?= e($d['kind_label']) ?></td><td dir="ltr" class="ltr-cell"><?= e($d['doc_number'] ?? '') ?></td>
                <td><?= e(fmt_money($d['amount'], 2)) ?></td><td><?= e(fmt_money($d['paid_amount'] ?? 0, 2)) ?></td><td><?= e(fmt_money($d['open_amount'], 2)) ?></td>
                <td><?= e($docStatus[$d['status']] ?? $d['status']) ?></td><td><?= $origin($d) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

<?php elseif ($tab === 'sales'): ?>
    <h3>לידים (<?= e(count($card['leads'])) ?>)</h3>
    <?php if ($card['leads'] === []): ?><p class="empty">אין לידים מקושרים ללקוח זה.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="leads-table">
        <thead><tr><th scope="col">שם</th><th scope="col">סטטוס</th><th scope="col">אימייל</th><th scope="col">טלפון</th><th scope="col">נוצר</th><th scope="col">לקוח</th></tr></thead>
        <tbody><?php foreach ($card['leads'] as $l): ?>
            <tr><td class="strong"><?= e($l['name']) ?></td><td><?= e($l['status']) ?></td><td dir="ltr" class="ltr-cell"><?= e($l['email'] ?? '') ?></td>
                <td dir="ltr" class="ltr-cell"><?= e($l['phone'] ?? '') ?></td><td class="muted"><?= e(fmt_dt($l['created_at'])) ?></td><td><?= $origin($l) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <h3>הצעות מחיר (<?= e(count($card['quotes'])) ?>)</h3>
    <?php if ($card['quotes'] === []): ?><p class="empty">אין הצעות מחיר מקושרות ללקוח זה.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="quotes-table">
        <thead><tr><th scope="col">מספר</th><th scope="col">סכום</th><th scope="col">סטטוס</th><th scope="col">נחתם</th><th scope="col">לקוח</th></tr></thead>
        <tbody><?php foreach ($card['quotes'] as $q): ?>
            <tr><td dir="ltr" class="ltr-cell"><?= e($q['quote_number'] ?? '') ?></td><td><?= e(fmt_money($q['total_amount'], 2)) ?></td><td><?= e($q['status']) ?></td>
                <td><?= e(fmt_date($q['signed_at'] ?? null)) ?></td><td><?= $origin($q) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

<?php elseif ($tab === 'assets'): ?>
    <?php if ($card['internal']): ?><p class="tab-actions"><a class="btn btn-ghost btn-sm" href="/assets?client_id=<?= e($id) ?>#new">+ נכס / דומיין חדש ללקוח</a></p><?php endif; ?>
    <?php if ($card['assets'] === []): ?><p class="empty">אין נכסים.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="assets-table">
        <thead><tr><th scope="col">סוג</th><th scope="col">דומיין</th><th scope="col">ספק / רשם</th><th scope="col">IP</th><th scope="col">SSL</th><th scope="col">חידוש</th><th scope="col">סטטוס</th><th scope="col">לקוח</th><?php if ($card['internal']): ?><th scope="col">פעולות</th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($card['assets'] as $a): ?>
            <tr><td><?= e($assetKinds[$a['kind']] ?? $a['kind']) ?></td>
                <td dir="ltr" class="ltr-cell strong"><?php if ($card['internal']): ?><a href="/assets/<?= e((int) $a['id']) ?>"><?= e($a['domain'] ?? '') ?></a><?php else: ?><?= e($a['domain'] ?? '') ?><?php endif; ?></td>
                <td><?= e(implode(' · ', array_filter([$a['provider'] ?? null, $a['registrar'] ?? null]))) ?></td>
                <td dir="ltr" class="ltr-cell"><?= e($a['ip_address'] ?? '') ?></td>
                <td><?= e($a['ssl_status'] ?? '—') ?></td>
                <td><?= e(fmt_date($a['expiry_date'] ?? null)) ?></td><td><?= e($a['status'] ?? '') ?></td><td><?= $origin($a) ?></td>
                <?php if ($card['internal']): ?>
                <td>
                    <a class="btn btn-ghost btn-sm" href="/assets/<?= e((int) $a['id']) ?>">עריכה</a>
                    <?php if (!empty($a['domain'])): ?>
                    <form method="post" action="/assets/<?= e((int) $a['id']) ?>/inspect" class="inline-form">
                        <?= Csrf::field() ?><input type="hidden" name="_return" value="/clients/<?= e($id) ?>?tab=assets">
                        <button type="submit" class="btn btn-ghost btn-sm">🔍 בדיקה</button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

<?php elseif ($tab === 'access' && $card['internal']): ?>
    <?php $assetDomains = array_column($card['assets'], 'domain', 'id'); ?>
    <p class="tab-actions"><a class="btn btn-ghost btn-sm" href="/vault?client_id=<?= e($id) ?>#new">+ גישה חדשה לכספת</a></p>
    <p class="muted">מטא-דאטה בלבד מהכספת: הסיסמאות עצמן לעולם אינן מוצגות בטבלה. הצפייה הזו נרשמה ביומן האירועים (vault.metadata_viewed).</p>
    <?php if ($card['vault'] === []): ?><p class="empty">אין רשומות כספת.</p><?php else: ?>
    <div class="table-wrap"><table class="table" id="vault-table">
        <thead><tr><th scope="col">כותרת</th><th scope="col">שירות</th><th scope="col">נכס מקושר</th><th scope="col">כתובת</th><th scope="col">משתמש</th><th scope="col">סוד שמור</th><th scope="col">הצפנה</th><th scope="col">לקוח</th><th scope="col">פעולות</th></tr></thead>
        <tbody><?php foreach ($card['vault'] as $v): ?>
            <tr><td class="strong"><a href="/vault/<?= e((int) $v['id']) ?>"><?= e($v['title'] ?? '') ?></a></td><td><?= e($v['service'] ?? '') ?></td>
                <td dir="ltr"><?php if (!empty($v['asset_id'])): ?><a href="/assets/<?= e((int) $v['asset_id']) ?>"><?= e($assetDomains[(int) $v['asset_id']] ?? ('#' . (int) $v['asset_id'])) ?></a><?php else: ?>—<?php endif; ?></td>
                <td dir="ltr" class="ltr-cell"><?= e($v['url'] ?? '') ?></td>
                <td dir="ltr" class="ltr-cell"><?= e($v['username'] ?? '') ?></td>
                <td><?= (int) $v['has_secret'] === 1 ? ($v['encryption'] === 'aes-256-gcm' ? 'מוצפן' : 'קיים במקור, לא יובא') : '—' ?></td>
                <td><?= e($v['encryption']) ?></td><td><?= $origin($v) ?></td>
                <td>
                    <a class="btn btn-ghost btn-sm" href="/vault/<?= e((int) $v['id']) ?>">פרטים ויומן</a>
                    <?php if ($v['encryption'] === 'aes-256-gcm'): ?>
                        <form method="post" action="/vault/<?= e((int) $v['id']) ?>/reveal" class="inline-form">
                            <?= Csrf::field() ?><button type="submit" class="btn btn-ghost btn-sm">🔓 חשיפה</button>
                        </form>
                    <?php endif; ?>
                </td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

<?php elseif ($tab === 'timeline'): ?>
    <h3>הכנסה לפי שנה (לפי חוק המסמך החשבונאי) · סה"כ <?= e(fmt_money($m['income_total'])) ?></h3>
    <?php $max = $m['income_by_year'] === [] ? 0 : max($m['income_by_year']); ?>
    <?php if ($m['income_by_year'] === []): ?><p class="empty">אין הכנסות.</p><?php endif; ?>
    <ul class="bars" id="income-bars">
        <?php foreach ($m['income_by_year'] as $y => $amt): ?>
            <li><span class="bar-label"><?= e($y) ?></span>
                <meter class="bar" min="0" max="<?= e($max) ?>" value="<?= e($amt) ?>"><?= e(fmt_money($amt)) ?></meter>
                <span class="bar-value"><?= e(fmt_money($amt)) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($familyRolled && count($m['income_by_origin']) > 0): ?>
        <p class="muted small">פילוח לפי לקוח: <?php foreach ($m['income_by_origin'] as $n => $amt): ?><?= e($n) ?> <?= e(fmt_money($amt)) ?> · <?php endforeach; ?></p>
    <?php endif; ?>
    <h3>ציר זמן (<?= e(count($card['timeline'])) ?>)</h3>
    <ol class="timeline" id="timeline-list">
        <?php foreach ($card['timeline'] as $it): ?>
            <li class="timeline-item"><span class="dot" aria-hidden="true"></span>
                <div><strong><?= e($it['kind']) ?></strong>: <?= e($it['text']) ?> <span class="muted">· <?= e($it['origin']) ?></span>
                    <time><?= e(strlen($it['date']) > 10 ? fmt_dt($it['date']) : fmt_date($it['date'])) ?></time></div></li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>
</section>
