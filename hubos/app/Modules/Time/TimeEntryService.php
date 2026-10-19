<?php

declare(strict_types=1);

namespace HubOS\Modules\Time;

use DateTimeImmutable;
use DateTimeZone;
use HubOS\Core\Events;
use HubOS\Core\HttpException;
use HubOS\Core\Permissions;
use HubOS\Core\Str;
use HubOS\Core\Tenant;
use HubOS\Core\ValidationException;
use HubOS\Modules\Work\WorkRefs;

/**
 * Time entries. Every entry is linked to a client (+ optional project/task) and
 * deducted from the client's active hours bank. Create/edit/delete are logged
 * to events with before/after.
 */
final class TimeEntryService
{
    public const MAX_SECONDS = 86400;

    private HoursBankService $banks;

    public function __construct(private Tenant $tenant, private Permissions $perms)
    {
        $this->banks = new HoursBankService($tenant, $perms);
    }

    /** "1:30", "1.5", "90m", "45 דק" → seconds; null when invalid. */
    public static function parseDuration(string $raw): ?int
    {
        $s = trim(str_replace(',', '.', $raw));
        if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $s, $m) === 1) {
            $secs = (int) $m[1] * 3600 + (int) $m[2] * 60;
        } elseif (preg_match('/^(\d{1,4})\s*(m|min|דק|דקות)$/u', $s, $m) === 1) {
            $secs = (int) $m[1] * 60;
        } elseif (preg_match('/^\d{1,2}(\.\d{1,4})?$/', $s) === 1) {
            $secs = (int) round((float) $s * 3600);
        } else {
            return null;
        }
        return ($secs > 0 && $secs <= self::MAX_SECONDS) ? $secs : null;
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem')))->format('Y-m-d');
    }

    /**
     * Manual entry (source = manual).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input client_id, project_id, task_id, description, entry_date, duration
     */
    public function create(array $user, array $input): int
    {
        $this->perms->authorize($user, 'time.track');
        $data = $this->validate($input, true);
        return $this->insert($user, $data + ['source' => 'manual', 'user_id' => (int) $user['id']]);
    }

    /**
     * Insert an already-validated entry (used by manual create and by the timer stop).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $data
     */
    public function insert(array $user, array $data): int
    {
        return $this->tenant->transaction(function (Tenant $t) use ($user, $data): int {
            $bank = $this->banks->activeBankFor((int) $data['client_id']);
            $before = $bank !== null ? $this->banks->summarize($bank) : null;
            $data['hours_bank_id'] = $bank !== null ? (int) $bank['id'] : null;
            $id = $t->insert('time_entries', $data);
            $row = WorkRefs::strip((array) $t->find('time_entries', $id));
            Events::record($t, (int) $user['id'], 'time_entry', $id, (int) $data['client_id'], 'time_entry.created', null, $row);
            if ($bank !== null) {
                $this->banks->recordThreshold((int) $user['id'], (int) $bank['id'], $before);
            }
            return $id;
        });
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     */
    public function update(array $user, int $id, array $input): void
    {
        $entry = $this->authorizeEntry($user, $id);
        $data = $this->validate($input, true);
        $this->tenant->transaction(function (Tenant $t) use ($user, $id, $entry, $data): void {
            if ((int) $entry['client_id'] !== (int) $data['client_id']) {
                $bank = $this->banks->activeBankFor((int) $data['client_id']);
                $data['hours_bank_id'] = $bank !== null ? (int) $bank['id'] : null;
            }
            $bankId = array_key_exists('hours_bank_id', $data) ? $data['hours_bank_id'] : $entry['hours_bank_id'];
            $beforeBank = null;
            if ($bankId !== null && ($b = $t->find('hours_banks', (int) $bankId)) !== null) {
                $beforeBank = $this->banks->summarize($b);
            }
            $data['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $t->update('time_entries', $id, $data);
            $after = WorkRefs::strip((array) $t->find('time_entries', $id));
            Events::record($t, (int) $user['id'], 'time_entry', $id, (int) $data['client_id'], 'time_entry.updated', WorkRefs::strip($entry), $after);
            if ($bankId !== null) {
                $this->banks->recordThreshold((int) $user['id'], (int) $bankId, $beforeBank);
            }
        });
    }

    /** @param array<string, mixed> $user */
    public function delete(array $user, int $id): void
    {
        $entry = $this->authorizeEntry($user, $id);
        $this->tenant->transaction(function (Tenant $t) use ($user, $id, $entry): void {
            $t->deleteWhere('time_entries', ['id' => $id]);
            Events::record($t, (int) $user['id'], 'time_entry', $id, $entry['client_id'] !== null ? (int) $entry['client_id'] : null,
                'time_entry.deleted', WorkRefs::strip($entry), null);
        });
    }

    /**
     * Own entries with time.track, any entry with time.manage.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function authorizeEntry(array $user, int $id): array
    {
        $this->perms->authorize($user, 'time.track');
        $entry = $this->tenant->find('time_entries', $id);
        if ($entry === null) {
            throw new HttpException(404, 'רישום הזמן לא נמצא');
        }
        if ((int) ($entry['user_id'] ?? 0) !== (int) $user['id'] && !$this->perms->can($user, 'time.manage')) {
            throw new HttpException(403, 'אפשר לערוך רק רישומי זמן שלך');
        }
        return $entry;
    }

    /**
     * Filtered list + totals. client_viewer: own client only and totals only (no rows).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters user_id, client_id, from, to
     * @return array{rows:list<array<string,mixed>>, total_seconds:int, by_client:array<string,int>, by_user:array<string,int>, count:int, filters:array<string,mixed>}
     */
    public function list(array $user, array $filters): array
    {
        $this->perms->authorize($user, 'time.view');
        $viewer = ($user['role'] ?? '') === 'client_viewer';
        $f = [
            'user_id'   => WorkRefs::intOrNull($filters['user_id'] ?? null),
            'client_id' => WorkRefs::intOrNull($filters['client_id'] ?? null),
            'from'      => self::date($filters['from'] ?? null),
            'to'        => self::date($filters['to'] ?? null),
        ];
        if ($viewer) {
            $f['client_id'] = $user['client_id'] !== null ? (int) $user['client_id'] : -1;
            $f['user_id'] = null;
        }
        $where = ['te.tenant_id = :tenant_id'];
        $params = [];
        foreach (['user_id' => 'te.user_id = :uid', 'client_id' => 'te.client_id = :cid'] as $k => $cond) {
            if ($f[$k] !== null) {
                $where[] = $cond;
                $params[$k === 'user_id' ? 'uid' : 'cid'] = $f[$k];
            }
        }
        $dateExpr = 'COALESCE(te.entry_date, substr(te.started_at, 1, 10), substr(te.created_at, 1, 10))';
        if ($f['from'] !== null) {
            $where[] = "{$dateExpr} >= :dfrom";
            $params['dfrom'] = $f['from'];
        }
        if ($f['to'] !== null) {
            $where[] = "{$dateExpr} <= :dto";
            $params['dto'] = $f['to'];
        }
        $rows = $this->tenant->select(
            "SELECT te.id, te.client_id, te.project_id, te.task_id, te.hours_bank_id, te.user_id, te.legacy_user_ref,
                    te.description, {$dateExpr} AS day, te.started_at, te.ended_at, te.duration_seconds, te.source,
                    c.name AS client_name, u.name AS user_name, t.title AS task_title, p.name AS project_name
               FROM time_entries te
          LEFT JOIN clients c  ON c.tenant_id = te.tenant_id AND c.id = te.client_id
          LEFT JOIN users u    ON u.tenant_id = te.tenant_id AND u.id = te.user_id
          LEFT JOIN tasks t    ON t.tenant_id = te.tenant_id AND t.id = te.task_id
          LEFT JOIN projects p ON p.tenant_id = te.tenant_id AND p.id = te.project_id
              WHERE " . implode(' AND ', $where) . "
           ORDER BY day DESC, te.id DESC LIMIT 1000",
            $params
        );
        $total = 0;
        $byClient = [];
        $byUser = [];
        foreach ($rows as $r) {
            $s = (int) $r['duration_seconds'];
            $total += $s;
            $byClient[(string) ($r['client_name'] ?? '—')] = ($byClient[(string) ($r['client_name'] ?? '—')] ?? 0) + $s;
            $uname = (string) ($r['user_name'] ?? ($r['legacy_user_ref'] !== null ? 'ייבוא' : '—'));
            $byUser[$uname] = ($byUser[$uname] ?? 0) + $s;
        }
        arsort($byClient);
        arsort($byUser);
        return [
            'rows'          => $viewer ? [] : $rows,
            'total_seconds' => $total,
            'by_client'     => $byClient,
            'by_user'       => $viewer ? [] : $byUser,
            'count'         => count($rows),
            'filters'       => $f,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input, bool $needDuration): array
    {
        $refs = WorkRefs::resolve(
            $this->tenant,
            WorkRefs::intOrNull($input['client_id'] ?? null),
            WorkRefs::intOrNull($input['project_id'] ?? null),
            WorkRefs::intOrNull($input['task_id'] ?? null),
        );
        $errors = $refs['errors'];
        $description = Str::clean(is_string($input['description'] ?? null) ? $input['description'] : '');
        if (Str::len($description) < 2 || Str::len($description) > 500) {
            $errors['description'] = 'חובה תיאור קצר (2–500 תווים)';
        }
        $date = self::date($input['entry_date'] ?? null) ?? (trim((string) ($input['entry_date'] ?? '')) === '' ? self::today() : null);
        if ($date === null) {
            $errors['entry_date'] = 'תאריך לא תקין';
        }
        $secs = null;
        if ($needDuration) {
            $secs = self::parseDuration(is_string($input['duration'] ?? null) ? $input['duration'] : '');
            if ($secs === null) {
                $errors['duration'] = 'משך לא תקין (למשל 1:30 או 1.5)';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return [
            'client_id'        => (int) $refs['client']['id'],
            'project_id'       => $refs['project'] !== null ? (int) $refs['project']['id'] : null,
            'task_id'          => $refs['task'] !== null ? (int) $refs['task']['id'] : null,
            'description'      => $description,
            'entry_date'       => $date,
            'duration_seconds' => $secs,
        ];
    }

    private static function date(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($v), $m) !== 1) {
            return null;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($v) : null;
    }
}
