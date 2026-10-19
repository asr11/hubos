<?php

declare(strict_types=1);

namespace HubOS\Modules\Time;

use HubOS\Core\Context;
use HubOS\Core\HttpException;
use HubOS\Core\Response;
use HubOS\Core\ValidationException;

/** /time page, manual entries, the live timer endpoints and bank settings. */
final class TimeController
{
    private const ENTRY_FIELDS = ['client_id', 'project_id', 'task_id', 'description', 'entry_date', 'duration'];

    // ------------------------------------------------------------------ /time

    public function index(Context $ctx): Response
    {
        return $this->renderIndex($ctx, [], []);
    }

    public function storeEntry(Context $ctx): Response
    {
        $input = $this->input($ctx, self::ENTRY_FIELDS);
        try {
            (new TimeEntryService($ctx->tenant(), $ctx->permissions()))->create($ctx->requireUser(), $input);
        } catch (ValidationException $e) {
            if ($this->back($ctx, '') !== '') {
                $ctx->flashError(implode(' · ', $e->errors));
                return Response::redirect($this->back($ctx, '/time'));
            }
            return $this->renderIndex($ctx, $e->errors, $input, 422);
        }
        $ctx->flash('רישום הזמן נשמר, נוכה מבנק השעות ונרשם ביומן האירועים');
        return Response::redirect($this->back($ctx, '/time'));
    }

    public function editEntry(Context $ctx, array $params): Response
    {
        $svc = new TimeEntryService($ctx->tenant(), $ctx->permissions());
        $entry = $svc->authorizeEntry($ctx->requireUser(), (int) $params['id']);
        return $this->renderEdit($ctx, $entry, [], []);
    }

    public function updateEntry(Context $ctx, array $params): Response
    {
        $id = (int) $params['id'];
        $svc = new TimeEntryService($ctx->tenant(), $ctx->permissions());
        $input = $this->input($ctx, self::ENTRY_FIELDS);
        try {
            $svc->update($ctx->requireUser(), $id, $input);
        } catch (ValidationException $e) {
            return $this->renderEdit($ctx, $svc->authorizeEntry($ctx->requireUser(), $id), $e->errors, $input, 422);
        }
        $ctx->flash('רישום הזמן עודכן ונרשם ביומן האירועים (לפני/אחרי)');
        return Response::redirect('/time');
    }

    public function deleteEntry(Context $ctx, array $params): Response
    {
        (new TimeEntryService($ctx->tenant(), $ctx->permissions()))->delete($ctx->requireUser(), (int) $params['id']);
        $ctx->flash('רישום הזמן נמחק ונרשם ביומן האירועים');
        return Response::redirect($this->back($ctx, '/time'));
    }

    public function bankSettings(Context $ctx, array $params): Response
    {
        try {
            (new HoursBankService($ctx->tenant(), $ctx->permissions()))->updateSettings($ctx->requireUser(), (int) $params['id'],
                $this->input($ctx, ['period', 'rollover', 'rollover_cap_hours', 'starts_on']));
            $ctx->flash('הגדרות בנק השעות נשמרו');
        } catch (ValidationException $e) {
            $ctx->flashError(implode(' · ', $e->errors));
        }
        return Response::redirect($this->back($ctx, '/time'));
    }

    // ------------------------------------------------------------------ timer

    /** GET /timer — JSON state for the header widget (server is the source of truth). */
    public function state(Context $ctx): Response
    {
        $user = $ctx->requireUser();
        $timer = $ctx->can('time.track') ? (new TimerService($ctx->tenant(), $ctx->permissions()))->current($user) : null;
        return Response::json([
            'active'      => $timer !== null,
            'running'     => $timer !== null && $timer['running'],
            'elapsed'     => $timer['elapsed_seconds'] ?? 0,
            'client_id'   => isset($timer['client_id']) ? (int) $timer['client_id'] : null,
            'client_name' => $timer['client_name'] ?? null,
            'task_title'  => $timer['task_title'] ?? null,
            'server_now'  => time(),
        ]);
    }

    public function start(Context $ctx): Response
    {
        return $this->timerAction($ctx, function (TimerService $s, array $u) use ($ctx): string {
            $s->start($u, $this->input($ctx, ['client_id', 'task_id', 'project_id', 'note']));
            return 'הטיימר הופעל';
        });
    }

    public function pause(Context $ctx): Response
    {
        return $this->timerAction($ctx, static function (TimerService $s, array $u): string {
            $s->pause($u);
            return 'הטיימר הושהה';
        });
    }

    public function resume(Context $ctx): Response
    {
        return $this->timerAction($ctx, static function (TimerService $s, array $u): string {
            $s->resume($u);
            return 'הטיימר חודש';
        });
    }

    public function stop(Context $ctx): Response
    {
        return $this->timerAction($ctx, static function (TimerService $s, array $u) use ($ctx): string {
            $s->stop($u, $ctx->input('description'));
            return 'הטיימר נעצר: נוצר רישום זמן ונוכה מבנק השעות';
        });
    }

    public function discard(Context $ctx): Response
    {
        return $this->timerAction($ctx, static function (TimerService $s, array $u): string {
            $s->discard($u);
            return 'הטיימר בוטל ללא רישום זמן';
        });
    }

    // ------------------------------------------------------------------ internals

    /** @param callable(TimerService, array<string,mixed>): string $fn */
    private function timerAction(Context $ctx, callable $fn): Response
    {
        $user = $ctx->requireUser();
        try {
            $ctx->flash($fn(new TimerService($ctx->tenant(), $ctx->permissions()), $user));
        } catch (ValidationException $e) {
            $ctx->flashError(implode(' · ', $e->errors));
        } catch (HttpException $e) {
            if ($e->status !== 409) {
                throw $e;
            }
            $ctx->flashError($e->getMessage());
        }
        return Response::redirect($this->back($ctx, '/time'));
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderIndex(Context $ctx, array $errors, array $old, int $status = 200): Response
    {
        $user = $ctx->requireUser();
        $internal = ($user['role'] ?? '') !== 'client_viewer';
        $svc = new TimeEntryService($ctx->tenant(), $ctx->permissions());
        $filters = [];
        foreach (['user_id', 'client_id', 'from', 'to'] as $k) {
            $filters[$k] = is_string($_GET[$k] ?? null) ? $_GET[$k] : null;
        }
        $result = $svc->list($user, $filters);
        $banks = new HoursBankService($ctx->tenant(), $ctx->permissions());
        $bankRows = $internal
            ? array_map(fn (array $b): array => $banks->summarize($b) + ['client_name' => (string) ($ctx->tenant()->find('clients', (int) $b['client_id'])['name'] ?? '—')],
                $ctx->tenant()->all('hours_banks', ['status' => 'active'], 'id', 'ASC'))
            : ($user['client_id'] !== null ? $banks->forClients([(int) $user['client_id']]) : []);
        return $ctx->render('time/index', [
            'title'     => 'שעות',
            'active'    => 'time',
            'result'    => $result,
            'banks'     => $bankRows,
            'internal'  => $internal,
            'canTrack'  => $ctx->can('time.track'),
            'canManage' => $ctx->can('time.manage'),
            'clients'   => $internal ? $ctx->tenant()->select('SELECT id, name FROM clients WHERE tenant_id = :tenant_id ORDER BY name LIMIT 1000') : [],
            'users'     => $internal ? $ctx->tenant()->select("SELECT id, name FROM users WHERE tenant_id = :tenant_id AND role <> 'client_viewer' ORDER BY name") : [],
            'tasks'     => $internal ? $ctx->tenant()->select("SELECT id, title, client_id FROM tasks WHERE tenant_id = :tenant_id AND client_id IS NOT NULL AND archived_at IS NULL AND status <> 'done' ORDER BY title LIMIT 1000") : [],
            'userId'    => (int) $user['id'],
            'errors'    => $errors,
            'old'       => $old,
        ], $status);
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderEdit(Context $ctx, array $entry, array $errors, array $old, int $status = 200): Response
    {
        $secs = (int) $entry['duration_seconds'];
        $old = $old !== [] ? $old : [
            'client_id'   => (string) $entry['client_id'],
            'project_id'  => (string) ($entry['project_id'] ?? ''),
            'task_id'     => (string) ($entry['task_id'] ?? ''),
            'description' => (string) ($entry['description'] ?? ''),
            'entry_date'  => (string) ($entry['entry_date'] ?? substr((string) $entry['started_at'], 0, 10)),
            'duration'    => sprintf('%d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60)),
        ];
        return $ctx->render('time/edit', [
            'title'   => 'עריכת רישום זמן #' . (int) $entry['id'],
            'active'  => 'time',
            'entry'   => $entry,
            'clients' => $ctx->tenant()->select('SELECT id, name FROM clients WHERE tenant_id = :tenant_id ORDER BY name LIMIT 1000'),
            'tasks'   => $ctx->tenant()->select("SELECT id, title, client_id FROM tasks WHERE tenant_id = :tenant_id AND client_id IS NOT NULL AND archived_at IS NULL ORDER BY title LIMIT 1000"),
            'errors'  => $errors,
            'old'     => $old,
        ], $status);
    }

    /**
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function input(Context $ctx, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $ctx->input($k);
        }
        return $out;
    }

    /** Internal return path from the form (_return), else $default. */
    private function back(Context $ctx, string $default): string
    {
        $r = $ctx->input('_return');
        return preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&%.\#]*$#', $r) === 1 ? $r : $default;
    }
}
