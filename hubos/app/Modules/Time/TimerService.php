<?php

declare(strict_types=1);

namespace HubOS\Modules\Time;

use Closure;
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
 * Server-side live timer: one active timer per user (UNIQUE(tenant_id, user_id)).
 * The server is the source of truth for elapsed time, so the timer survives
 * refresh/navigation. Stop creates a time entry (description required) that is
 * deducted from the client's active hours bank.
 */
final class TimerService
{
    /** @var Closure(): int */
    private Closure $clock;

    /** @param (Closure(): int)|null $clock unix seconds (injectable for tests) */
    public function __construct(private Tenant $tenant, private Permissions $perms, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Current timer of the user with computed elapsed seconds, or null.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public function current(array $user): ?array
    {
        $row = $this->tenant->selectOne(
            'SELECT a.*, c.name AS client_name, t.title AS task_title, p.name AS project_name
               FROM active_timers a
          LEFT JOIN clients c  ON c.tenant_id = a.tenant_id AND c.id = a.client_id
          LEFT JOIN tasks t    ON t.tenant_id = a.tenant_id AND t.id = a.task_id
          LEFT JOIN projects p ON p.tenant_id = a.tenant_id AND p.id = a.project_id
              WHERE a.tenant_id = :tenant_id AND a.user_id = :uid',
            ['uid' => (int) $user['id']]
        );
        if ($row === null) {
            return null;
        }
        $row['elapsed_seconds'] = $this->elapsed($row);
        $row['running'] = $row['paused_at'] === null;
        $row['server_now'] = ($this->clock)();
        return $row;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input client_id (or derived from task_id), task_id, project_id, note
     */
    public function start(array $user, array $input): int
    {
        $this->perms->authorize($user, 'time.track');
        $refs = WorkRefs::resolve(
            $this->tenant,
            WorkRefs::intOrNull($input['client_id'] ?? null),
            WorkRefs::intOrNull($input['project_id'] ?? null),
            WorkRefs::intOrNull($input['task_id'] ?? null),
        );
        if ($refs['errors'] !== []) {
            throw new ValidationException($refs['errors']);
        }
        $note = Str::limit(Str::clean(is_string($input['note'] ?? null) ? $input['note'] : ''), 200);
        $now = self::iso(($this->clock)());

        return $this->tenant->transaction(function (Tenant $t) use ($user, $refs, $note, $now): int {
            if ($t->count('active_timers', ['user_id' => (int) $user['id']]) > 0) {
                throw new HttpException(409, 'כבר רץ טיימר. יש לעצור אותו לפני שמתחילים חדש.');
            }
            $data = [
                'user_id'          => (int) $user['id'],
                'client_id'        => (int) $refs['client']['id'],
                'project_id'       => $refs['project'] !== null ? (int) $refs['project']['id'] : null,
                'task_id'          => $refs['task'] !== null ? (int) $refs['task']['id'] : null,
                'note'             => $note === '' ? null : $note,
                'started_at'       => $now,
                'first_started_at' => $now,
            ];
            $id = $t->insert('active_timers', $data);
            Events::record($t, (int) $user['id'], 'timer', $id, $data['client_id'], 'timer.started', null, $data);
            return $id;
        });
    }

    /** @param array<string, mixed> $user */
    public function pause(array $user): void
    {
        $this->perms->authorize($user, 'time.track');
        $this->tenant->transaction(function (Tenant $t) use ($user): void {
            $timer = $this->require($user);
            if ($timer['paused_at'] !== null) {
                return;
            }
            $now = ($this->clock)();
            $acc = $this->elapsed($timer, $now);
            $t->update('active_timers', (int) $timer['id'], ['accumulated_seconds' => $acc, 'paused_at' => self::iso($now)]);
            Events::record($t, (int) $user['id'], 'timer', (int) $timer['id'], (int) $timer['client_id'], 'timer.paused',
                ['accumulated_seconds' => (int) $timer['accumulated_seconds']], ['accumulated_seconds' => $acc]);
        });
    }

    /** @param array<string, mixed> $user */
    public function resume(array $user): void
    {
        $this->perms->authorize($user, 'time.track');
        $this->tenant->transaction(function (Tenant $t) use ($user): void {
            $timer = $this->require($user);
            if ($timer['paused_at'] === null) {
                return;
            }
            $now = self::iso(($this->clock)());
            $t->update('active_timers', (int) $timer['id'], ['started_at' => $now, 'paused_at' => null]);
            Events::record($t, (int) $user['id'], 'timer', (int) $timer['id'], (int) $timer['client_id'], 'timer.resumed',
                ['paused_at' => $timer['paused_at']], ['started_at' => $now]);
        });
    }

    /**
     * Stop → time entry (description required) → bank deduction. Returns the entry id.
     *
     * @param array<string, mixed> $user
     */
    public function stop(array $user, string $description): int
    {
        $this->perms->authorize($user, 'time.track');
        $description = Str::clean($description);
        if (Str::len($description) < 2 || Str::len($description) > 500) {
            throw new ValidationException(['description' => 'בעצירת הטיימר חובה לכתוב תיאור קצר (2–500 תווים)']);
        }
        return $this->tenant->transaction(function (Tenant $t) use ($user, $description): int {
            $timer = $this->require($user);
            $nowTs = ($this->clock)();
            $secs = min($this->elapsed($timer, $nowTs), TimeEntryService::MAX_SECONDS);
            $startIso = (string) ($timer['first_started_at'] ?? $timer['started_at']);
            $entryId = (new TimeEntryService($t, $this->perms))->insert($user, [
                'client_id'        => (int) $timer['client_id'],
                'project_id'       => $timer['project_id'] !== null ? (int) $timer['project_id'] : null,
                'task_id'          => $timer['task_id'] !== null ? (int) $timer['task_id'] : null,
                'user_id'          => (int) $user['id'],
                'description'      => $description,
                'entry_date'       => (new DateTimeImmutable($startIso))->setTimezone(new DateTimeZone('Asia/Jerusalem'))->format('Y-m-d'),
                'started_at'       => $startIso,
                'ended_at'         => self::iso($nowTs),
                'duration_seconds' => $secs,
                'source'           => 'timer',
            ]);
            $t->deleteWhere('active_timers', ['id' => (int) $timer['id']]);
            Events::record($t, (int) $user['id'], 'timer', (int) $timer['id'], (int) $timer['client_id'], 'timer.stopped',
                WorkRefs::strip($timer), ['time_entry_id' => $entryId, 'duration_seconds' => $secs]);
            return $entryId;
        });
    }

    /** Discard the running timer without creating an entry (logged). @param array<string, mixed> $user */
    public function discard(array $user): void
    {
        $this->perms->authorize($user, 'time.track');
        $this->tenant->transaction(function (Tenant $t) use ($user): void {
            $timer = $this->require($user);
            $t->deleteWhere('active_timers', ['id' => (int) $timer['id']]);
            Events::record($t, (int) $user['id'], 'timer', (int) $timer['id'], (int) $timer['client_id'], 'timer.discarded',
                WorkRefs::strip($timer) + ['elapsed_seconds' => $this->elapsed($timer)], null);
        });
    }

    /** @param array<string, mixed> $timer */
    public function elapsed(array $timer, ?int $now = null): int
    {
        $now ??= ($this->clock)();
        $acc = (int) ($timer['accumulated_seconds'] ?? 0);
        if ($timer['paused_at'] !== null) {
            return $acc;
        }
        $started = strtotime((string) $timer['started_at']);
        return $acc + max(0, $now - ($started === false ? $now : $started));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function require(array $user): array
    {
        $timer = $this->tenant->all('active_timers', ['user_id' => (int) $user['id']], 'id', 'DESC', 1)[0] ?? null;
        if ($timer === null) {
            throw new HttpException(409, 'אין טיימר פעיל');
        }
        return $timer;
    }

    private static function iso(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
